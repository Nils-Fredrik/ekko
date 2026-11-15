<?php

namespace App\Performance;

use App\Metrics\DailyAggregator;
use App\Models\Brand;
use App\Models\Engine;
use App\Models\Market;
use App\Models\Prompt;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/** Reproducible, isolated T37 fixture. No provider, queue, mail or object-storage calls. */
final class PerformanceFixture
{
    public const ENGINES = ['chatgpt_ui', 'gemini_ui', 'google_aio', 'google_ai_mode', 'claude_haiku', 'perplexity_api'];

    public function __construct(private TenantContext $context, private TenantRls $rls) {}

    /** @return array{tenant_id: int, brand_id: int, email: string, from: string, to: string, expected_responses: int} */
    public function create(string $key, string $password, int $days = 365, int $prompts = 100): array
    {
        $this->validateKey($key);
        if ($days < 1 || $days > 365 || $prompts < 1 || $prompts > 100 || strlen($password) < 24) {
            throw new InvalidArgumentException('Use 1–365 days, 1–100 prompts and a password of at least 24 characters.');
        }
        if (Engine::query()->whereIn('key', self::ENGINES)->count() !== 6) {
            throw new RuntimeException('The six configured production engines must exist before creating the fixture.');
        }
        if (User::query()->whereIn('email', $this->emails($key))->exists() || Team::query()->where('name', 'T37 synthetic '.$key)->exists()) {
            throw new RuntimeException('Fixture already exists; verify or delete it before creating another.');
        }
        [$tenant, $reader] = DB::transaction(function () use ($key, $password): array {
            [$ownerEmail, $readerEmail] = $this->emails($key);
            $owner = User::query()->create(['name' => 'T37 synthetic owner', 'email' => $ownerEmail, 'password' => Str::random(64)]);
            $reader = User::query()->create(['name' => 'T37 synthetic reader', 'email' => $readerEmail, 'password' => $password]);
            $tenant = new Team;
            $tenant->forceFill(['name' => 'T37 synthetic '.$key, 'user_id' => $owner->id, 'personal_team' => false, 'timezone' => 'UTC'])->save();
            $tenant->users()->attach($reader, ['role' => 'reader']);
            $reader->forceFill(['email_verified_at' => now(), 'current_team_id' => $tenant->id])->save();

            return [$tenant, $reader];
        });
        [$brand, $market] = $this->scope($tenant->id, function () use ($key, $prompts): array {
            $brand = Brand::query()->create(['name' => 'T37 synthetic brand', 'slug' => $key, 'primary_domain' => 't37.example.invalid']);
            // Inactive market prevents the scheduler from ever dispatching paid calls.
            $market = Market::query()->create(['brand_id' => $brand->id, 'country' => 'NO', 'language' => 'nb', 'is_active' => false]);
            for ($i = 1; $i <= $prompts; $i++) {
                $text = "Synthetic performance prompt {$i}";
                Prompt::query()->create(['market_id' => $market->id, 'text' => $text, 'text_hash' => hash('sha256', $text), 'intent' => 'category', 'funnel_stage' => 'awareness', 'priority' => 'standard', 'is_active' => false]);
            }

            return [$brand, $market];
        });
        $end = CarbonImmutable::today('UTC');
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = $end->subDays($i)->toDateString();
            $this->scope($tenant->id, function () use ($tenant, $brand, $market, $date, $prompts): void {
                $batch = DB::table('run_batches')->insertGetId(['tenant_id' => $tenant->id, 'market_id' => $market->id, 'scheduled_for' => $date.' 12:00:00+00', 'status' => 'completed', 'planned_runs' => $prompts * 18, 'completed_runs' => $prompts * 18]);
                $engines = json_encode(self::ENGINES, JSON_THROW_ON_ERROR);
                DB::insert("INSERT INTO runs (tenant_id, run_batch_id, prompt_id, engine_key, repetition, status)
                    SELECT ?, ?, p.id, e.key, r.n, 'extracted' FROM prompts p
                    CROSS JOIN jsonb_array_elements_text(?::jsonb) e(key) CROSS JOIN generate_series(1, 3) r(n)
                    WHERE p.tenant_id = ? AND p.market_id = ?", [$tenant->id, $batch, $engines, $tenant->id, $market->id]);
                DB::insert("INSERT INTO responses (tenant_id, run_id, engine_key, model_id_reported, answer_text, answer_language, answer_type, collected_at)
                    SELECT tenant_id, id, engine_key, 't37-synthetic-v1', repeat('Synthetic T37 answer. ', 100), 'nb', 'list', ?::timestamptz
                    FROM runs WHERE tenant_id = ? AND run_batch_id = ?", [$date.' 12:00:00+00', $tenant->id, $batch]);
                DB::insert("INSERT INTO mentions (tenant_id, response_id, entity_type, entity_id, surface_name, list_position, is_recommended, sentiment, detection)
                    SELECT s.tenant_id, s.id, 'brand', ?, 'T37 synthetic brand', r.repetition, r.repetition = 1, 0.5, 'deterministic'
                    FROM responses s JOIN runs r ON r.id = s.run_id WHERE s.tenant_id = ? AND r.run_batch_id = ? AND r.repetition <= 2", [$brand->id, $tenant->id, $batch]);
                DB::insert("INSERT INTO citations (tenant_id, response_id, url, url_normalized, domain, ownership, source_type)
                    SELECT s.tenant_id, s.id, 'https://t37.example.invalid/example', 'https://t37.example.invalid/example', 't37.example.invalid', 'own', 'other'
                    FROM responses s JOIN runs r ON r.id = s.run_id WHERE s.tenant_id = ? AND r.run_batch_id = ?", [$tenant->id, $batch]);
                // Exercise the actual aggregation implementation, not hand-built shortcuts.
                app(DailyAggregator::class)->aggregate($date);
            });
        }

        return ['tenant_id' => $tenant->id, 'brand_id' => $brand->id, 'email' => $reader->email, 'from' => $end->subDays($days - 1)->toDateString(), 'to' => $end->toDateString(), 'expected_responses' => $days * $prompts * 18];
    }

    /** @return array<string, int|string|null> */
    public function verify(string $key): array
    {
        $tenant = $this->tenant($key);

        return $this->scope($tenant->id, function () use ($tenant): array {
            $result = ['tenant_id' => $tenant->id, 'brand_id' => (int) DB::table('brands')->where('tenant_id', $tenant->id)->value('id')];
            foreach (['prompts', 'runs', 'responses', 'mentions', 'citations', 'agg_daily', 'agg_source_daily'] as $table) {
                $result[$table] = DB::table($table)->where('tenant_id', $tenant->id)->count();
            }
            $result['days'] = DB::table('agg_daily')->where('tenant_id', $tenant->id)->distinct()->count('date');
            $result['engines'] = DB::table('runs')->where('tenant_id', $tenant->id)->distinct()->count('engine_key');
            $result['repetitions'] = DB::table('runs')->where('tenant_id', $tenant->id)->distinct()->count('repetition');
            $result['aggregated_responses'] = (int) DB::table('agg_daily')->where('tenant_id', $tenant->id)->sum('responses');
            $result['from'] = DB::table('agg_daily')->where('tenant_id', $tenant->id)->min('date');
            $result['to'] = DB::table('agg_daily')->where('tenant_id', $tenant->id)->max('date');

            return $result;
        });
    }

    public function delete(string $key): void
    {
        $tenant = $this->tenant($key);
        $this->scope($tenant->id, function () use ($tenant, $key): void {
            $tenant = Team::query()->lockForUpdate()->findOrFail($tenant->id);
            $users = User::query()->whereIn('email', $this->emails($key))->lockForUpdate()->get();
            $ids = $users->modelKeys();
            // Refuse deletion if the fixture has acquired real members, clients or ownership elsewhere.
            if ($users->count() !== 2 || ! in_array($tenant->user_id, $ids, true)
                || $tenant->clients()->exists()
                || $tenant->users()->whereNotIn('users.id', $ids)->exists()
                || Team::query()->whereIn('user_id', $ids)->where('id', '!=', $tenant->id)->exists()
                || DB::table('team_user')->whereIn('user_id', $ids)->where('team_id', '!=', $tenant->id)->exists()
                || $users->contains(fn (User $user) => $user->isPlatformAdmin())) {
                throw new RuntimeException('Fixture identity changed; refusing cleanup.');
            }
            foreach ($users as $user) {
                $user->tokens()->delete();
                DB::table('sessions')->where('user_id', $user->id)->delete();
                $user->forceFill(['current_team_id' => null])->save();
            }
            $tenant->delete(); // All tenant-owned tables have cascading tenant foreign keys.
            foreach ($users as $user) {
                $user->delete();
            }
        });
    }

    private function tenant(string $key): Team
    {
        $this->validateKey($key);

        return Team::query()->where('name', 'T37 synthetic '.$key)->sole();
    }

    private function validateKey(string $key): void
    {
        if (! Str::isUuid($key)) {
            throw new InvalidArgumentException('Fixture key must be a UUID.');
        }
    }

    /** @return array{string, string} */
    private function emails(string $key): array
    {
        return ["t37-owner-{$key}@example.invalid", "t37-reader-{$key}@example.invalid"];
    }

    /** @template T
     * @param  callable(): T  $callback
     * @return T
     */
    private function scope(int $tenantId, callable $callback): mixed
    {
        return $this->context->run($tenantId, fn () => $this->rls->run([$tenantId], $callback));
    }
}

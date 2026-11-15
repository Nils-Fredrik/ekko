<?php

use App\Models\Engine;
use App\Models\Team;
use App\Models\User;
use App\Performance\PerformanceFixture;
use App\Tenancy\Rls\TenantRls;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    foreach (PerformanceFixture::ENGINES as $key) {
        Engine::factory()->create(['key' => $key]);
    }
});

it('creates the real collection and aggregate dimensions and removes only its own fixture', function () {
    $foreign = Team::factory()->create();
    $key = (string) Str::uuid();
    $fixture = app(PerformanceFixture::class);
    $created = $fixture->create($key, Str::random(32), days: 2, prompts: 3);
    $counts = $fixture->verify($key);
    expect($counts)->toMatchArray(['prompts' => 3, 'runs' => 108, 'responses' => 108, 'mentions' => 72, 'citations' => 108, 'agg_daily' => 36, 'agg_source_daily' => 12, 'days' => 2, 'engines' => 6, 'repetitions' => 3, 'aggregated_responses' => 108]);
    app(TenantRls::class)->run([$created['tenant_id']], function () use ($created) {
        expect((int) DB::table('agg_daily')->where('tenant_id', $created['tenant_id'])->sum('brand_mentioned'))->toBe(72)
            ->and(DB::table('markets')->where('tenant_id', $created['tenant_id'])->where('is_active', true)->exists())->toBeFalse();
    });
    $fixture->delete($key);
    expect(Team::query()->find($foreign->id))->not->toBeNull()
        ->and(Team::query()->find($created['tenant_id']))->toBeNull()
        ->and(User::query()->where('email', $created['email'])->exists())->toBeFalse();
    app(TenantRls::class)->run([$created['tenant_id']], function () use ($created) {
        foreach (['runs', 'responses', 'mentions', 'citations', 'agg_daily', 'agg_source_daily'] as $table) {
            expect(DB::table($table)->where('tenant_id', $created['tenant_id'])->count())->toBe(0);
        }
    });
});

it('refuses to delete a fixture after another person joins it', function () {
    $key = (string) Str::uuid();
    $fixture = app(PerformanceFixture::class);
    $created = $fixture->create($key, Str::random(32), days: 1, prompts: 1);
    $tenant = Team::query()->findOrFail($created['tenant_id']);
    $tenant->users()->attach(User::factory()->create(), ['role' => 'reader']);
    expect(fn () => $fixture->delete($key))->toThrow(RuntimeException::class)
        ->and($tenant->fresh())->not->toBeNull();
});

it('requires the production engine catalogue and a unique fixture identity', function () {
    $fixture = app(PerformanceFixture::class);
    expect(fn () => $fixture->create('not-a-uuid', Str::random(32)))->toThrow(InvalidArgumentException::class);
    Engine::query()->delete();
    expect(fn () => $fixture->create((string) Str::uuid(), Str::random(32)))->toThrow(RuntimeException::class);
    expect(Team::query()->count())->toBe(0);
});

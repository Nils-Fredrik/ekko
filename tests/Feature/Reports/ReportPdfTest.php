<?php

use App\AnthropicBudget\Account;
use App\Mail\ReportMail;
use App\Models\AnthropicInsightAllocation;
use App\Models\Engine;
use App\Models\EnginePrice;
use App\Models\Report;
use App\Models\ReportSchedule;
use App\Models\Team;
use App\Models\UsageEvent;
use App\Reports\ReportPublisher;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\FakePdfBuilder;
use Tests\Support\Metrics\MetricsWorld;

test('a monthly report pdf is stored and mailed once, with the insight allocation consumed once', function () {
    Storage::fake(config()->string('ekko.reports.disk'));
    Mail::fake();
    config(['services.anthropic.key' => 'test-key', 'ekko.anthropic.monthly_pool_usd' => '100']);
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'Synligheten holder seg.']],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 40],
        ]),
    ]);
    $this->travelTo('2026-11-17 12:00:00');
    $world = MetricsWorld::create('UTC');
    $engine = Engine::factory()->create(['key' => 'claude_sonnet', 'provider' => 'anthropic', 'is_enabled' => true]);
    EnginePrice::factory()->for($engine)->create([
        'valid_from' => '2026-01-01 00:00:00',
        'price_per_call_usd' => '0.01000000',
        'price_per_1k_input_tokens_usd' => '1.00000000',
        'price_per_1k_output_tokens_usd' => '1.00000000',
        'price_per_search_usd' => '0.00000000',
    ]);
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);
    $this->actingAs($owner)->put(route('teams.branding.update', $world->tenant), [
        'primary' => '#112233',
        'logo' => UploadedFile::fake()->image('logo.png', 8, 8),
    ])->assertRedirect();

    app(TenantContext::class)->set($world->tenant->id);
    ReportSchedule::factory()->create([
        'brand_id' => $world->brand->id,
        'cadence' => 'monthly',
        'locale' => 'nb',
        'recipients' => ['reports@example.test'],
    ]);
    AnthropicInsightAllocation::query()->create([
        'brand_id' => $world->brand->id,
        'cycle_start' => '2026-10-01',
        'cost_usd' => '0.05000000',
    ]);
    AnthropicInsightAllocation::query()->create([
        'brand_id' => $world->brand->id,
        'cycle_start' => '2026-11-01',
        'cost_usd' => '0.05000000',
    ]);

    $started = microtime(true);
    $this->artisan('ekko:send-reports', ['--tenant' => [$world->tenant->id], '--sync' => true])->assertSuccessful();
    expect(microtime(true) - $started)->toBeLessThan(30);

    $report = Report::query()->sole();
    $pdf = Storage::disk(config()->string('ekko.reports.disk'))->get($report->pdf_uri);
    expect($pdf)->toStartWith('%PDF')
        ->and($report->sent_at)->not->toBeNull()
        ->and(AnthropicInsightAllocation::query()->whereDate('cycle_start', '2026-11-01')->sole()->consumed_at)->not->toBeNull()
        ->and(AnthropicInsightAllocation::query()->whereDate('cycle_start', '2026-10-01')->sole()->consumed_at)->toBeNull()
        ->and(UsageEvent::query()->where('kind', 'pdf')->count())->toBe(1)
        ->and(DB::table('anthropic_credit_spend')->where('entry', 'insight:'.$world->brand->id.':2026-11-01')->count())->toBe(1)
        ->and(DB::table('anthropic_budget_holds')->where('operation', 'like', 'insight:'.$world->brand->id.':2026-11-01:overage:%')->whereNotNull('settled_at')->count())->toBe(1)
        ->and(DB::table('anthropic_budget_holds')->whereNull('settled_at')->count())->toBe(0);
    Mail::assertSent(ReportMail::class, fn ($mail) => $mail->hasTo('reports@example.test'));
    Http::assertSentCount(1);

    $schedule = ReportSchedule::query()->sole();
    $world->tenant->refresh();
    app()->setLocale('nb');
    $norwegian = view('reports.pdf', app(ReportPublisher::class)->viewData($world->tenant, $schedule, '2026-10-01', '2026-10-31', 'Synligheten holder seg.'))->render();
    app()->setLocale('sv');
    $swedish = view('reports.pdf', app(ReportPublisher::class)->viewData($world->tenant, $schedule, '2026-10-01', '2026-10-31', 'Synligheten holder seg.'))->render();
    expect($norwegian)->toContain('Nøkkeltall')->toContain('data:image/png')
        ->and($swedish)->toContain('Nyckeltal')->not->toContain('Nøkkeltall');

    $partner = Team::factory()->tenant()->create([
        'name' => 'Byrapartner',
        'branding' => ['primary' => '#abcdef'],
    ]);
    $world->tenant->forceFill(['partner_team_id' => $partner->id])->save();
    $schedule->forceFill(['branding' => 'partner'])->save();
    $profile = app(ReportPublisher::class)->viewData($world->tenant->fresh(), $schedule->fresh(), '2026-10-01', '2026-10-31', null)['profile'];
    expect($profile['color'])->toBe('#abcdef')->and($profile['name'])->toBe('Byrapartner');

    $this->artisan('ekko:send-reports', ['--tenant' => [$world->tenant->id], '--sync' => true])->assertSuccessful();
    Http::assertSentCount(1);
    Mail::assertSentCount(1);
    clearReportBudget();
});

test('a failed pdf render does not spend the insight allocation again', function () {
    Storage::fake(config()->string('ekko.reports.disk'));
    Mail::fake();
    config(['services.anthropic.key' => 'test-key', 'ekko.anthropic.monthly_pool_usd' => '100']);
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'Synligheten holder seg.']],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 40],
        ]),
    ]);
    $this->travelTo('2026-11-17 12:00:00');
    $world = MetricsWorld::create('UTC');
    $engine = Engine::factory()->create(['key' => 'claude_sonnet', 'provider' => 'anthropic', 'is_enabled' => true]);
    EnginePrice::factory()->for($engine)->create([
        'valid_from' => '2026-01-01 00:00:00',
        'price_per_call_usd' => '0.01000000',
        'price_per_1k_input_tokens_usd' => '1.00000000',
        'price_per_1k_output_tokens_usd' => '1.00000000',
        'price_per_search_usd' => '0.00000000',
    ]);
    app(TenantContext::class)->set($world->tenant->id);
    ReportSchedule::factory()->create([
        'brand_id' => $world->brand->id,
        'cadence' => 'monthly',
        'locale' => 'sv',
        'recipients' => ['one@example.test', 'two@example.test'],
    ]);
    AnthropicInsightAllocation::query()->create([
        'brand_id' => $world->brand->id,
        'cycle_start' => '2026-11-01',
        'cost_usd' => '0.05000000',
    ]);

    Pdf::swap(new class extends FakePdfBuilder
    {
        public int $saves = 0;

        public function save(string $path): self
        {
            $this->saves++;
            if ($this->saves === 1) {
                throw new RuntimeException('render failed');
            }
            Storage::disk(config()->string('ekko.reports.disk'))->put($path, '%PDF-fake');

            return $this;
        }
    });

    $this->artisan('ekko:send-reports', ['--tenant' => [$world->tenant->id], '--sync' => true])->assertSuccessful();
    expect(Report::query()->count())->toBe(0)
        ->and(AnthropicInsightAllocation::query()->sole()->consumed_at)->not->toBeNull();
    Http::assertSent(fn ($request): bool => str_contains((string) data_get($request->data(), 'messages.0.content'), 'på svenska'));

    $this->artisan('ekko:send-reports', ['--tenant' => [$world->tenant->id], '--sync' => true])->assertSuccessful();
    expect(Report::query()->sole()->pdf_uri)->not->toBeNull();
    Http::assertSentCount(1);
    Mail::assertSent(ReportMail::class, fn (ReportMail $mail): bool => $mail->hasTo('one@example.test') && $mail->hasTo('two@example.test'));
    clearReportBudget();
});

function clearReportBudget(): void
{
    $budget = Account::independent()->db();
    $budget->statement("SET lock_timeout = '2s'");
    $budget->table('anthropic_budget_holds')->delete();
    $budget->table('anthropic_budget_cycles')->delete();
    DB::purge('anthropic_budget');
}

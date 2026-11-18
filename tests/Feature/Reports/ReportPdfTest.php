<?php

use App\AnthropicBudget\Account;
use App\Engines\EnginePricing;
use App\Engines\EngineResult;
use App\Mail\ReportMail;
use App\Models\AnthropicInsightAllocation;
use App\Models\Engine;
use App\Models\EnginePrice;
use App\Models\Report;
use App\Models\ReportSchedule;
use App\Models\Team;
use App\Models\UsageEvent;
use App\Reports\InsightPrice;
use App\Reports\InsightWriter;
use App\Reports\ReportPublisher;
use App\Reports\ReportWindow;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToWriteFile;
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
    $this->artisan('ekko:send-reports', ['--tenant' => [$world->tenant->id]])->assertSuccessful();
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
    expect($norwegian)->toContain('Nøkkeltall')->toContain('Anbefaling')->toContain('Snittposisjon')
        ->toContain('Prompts med størst endring')->toContain('Mest siterte domener')->toContain('Kildegap')
        ->toContain('AI-robottreff')->toContain('Modellbytter')->toContain('Innsikt')->toContain('data:image/png')
        ->and($swedish)->toContain('Nyckeltal')->toContain('Rekommendation')->toContain('Snittposition')
        ->toContain('Prompter med störst förändring')->toContain('Mest citerade domäner')->toContain('Källgap')
        ->toContain('AI-robotträffar')->toContain('Modellbyten')->toContain('Insikt')
        ->not->toContain('Nøkkeltall')->not->toContain('Anbefaling')->not->toContain('Kildegap');

    $partner = Team::factory()->tenant()->create([
        'name' => 'Byrapartner',
        'branding' => ['primary' => '#abcdef'],
    ]);
    $world->tenant->forceFill(['partner_team_id' => $partner->id])->save();
    $schedule->forceFill(['branding' => 'partner'])->save();
    $profile = app(ReportPublisher::class)->viewData($world->tenant->fresh(), $schedule->fresh(), '2026-10-01', '2026-10-31', null)['profile'];
    expect($profile['color'])->toBe('#abcdef')->and($profile['name'])->toBe('Byrapartner');

    $this->artisan('ekko:send-reports', ['--tenant' => [$world->tenant->id]])->assertSuccessful();
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

    $this->artisan('ekko:send-reports', ['--tenant' => [$world->tenant->id]])->assertFailed();
    expect(Report::query()->sole()->pdf_uri)->toBeNull()
        ->and(AnthropicInsightAllocation::query()->sole()->consumed_at)->not->toBeNull();
    Http::assertSent(fn ($request): bool => str_contains((string) data_get($request->data(), 'messages.0.content'), 'på svenska'));

    $this->artisan('ekko:send-reports', ['--tenant' => [$world->tenant->id]])->assertSuccessful();
    expect(Report::query()->sole()->pdf_uri)->not->toBeNull();
    Http::assertSentCount(1);
    Mail::assertSent(ReportMail::class, fn (ReportMail $mail): bool => $mail->hasTo('one@example.test'));
    Mail::assertSent(ReportMail::class, fn (ReportMail $mail): bool => $mail->hasTo('two@example.test'));
    expect(Mail::sent(ReportMail::class))->toHaveCount(2);
    clearReportBudget();
});

test('a second report run cannot claim an insight allocation that is already claimed', function () {
    config(['services.anthropic.key' => 'test-key', 'ekko.anthropic.monthly_pool_usd' => '100']);
    Http::fake();
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
    AnthropicInsightAllocation::query()->create([
        'brand_id' => $world->brand->id,
        'cycle_start' => '2026-11-01',
        'cost_usd' => '0.05000000',
    ]);

    $writer = app(InsightWriter::class);
    $rls = app(TenantRls::class);
    $first = $rls->run([$world->tenant->id], fn () => $writer->admit($world->brand));
    $second = $rls->run([$world->tenant->id], fn () => $writer->admit($world->brand));

    expect($first)->not->toBeNull()->and($second)->toBeNull();
    Http::assertNothingSent();
    expect(AnthropicInsightAllocation::query()->sole()->consumed_at)->not->toBeNull();

    $rls->run([$world->tenant->id], fn () => $writer->release($first));
    expect(AnthropicInsightAllocation::query()->sole()->consumed_at)->toBeNull();
    clearReportBudget();
});

test('an unknown insight outage keeps the claim and the reservation', function () {
    Storage::fake(config()->string('ekko.reports.disk'));
    Mail::fake();
    config(['services.anthropic.key' => 'test-key', 'ekko.anthropic.monthly_pool_usd' => '100']);
    Http::fake(function (): void {
        throw new ConnectionException('timed out');
    });
    Pdf::swap(new class extends FakePdfBuilder
    {
        public function save(string $path): self
        {
            Storage::disk(config()->string('ekko.reports.disk'))->put($path, '%PDF-fake');

            return $this;
        }
    });
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
        'locale' => 'nb',
        'recipients' => ['reports@example.test'],
    ]);
    AnthropicInsightAllocation::query()->create([
        'brand_id' => $world->brand->id,
        'cycle_start' => '2026-11-01',
        'cost_usd' => '0.05000000',
    ]);

    $this->artisan('ekko:send-reports', ['--tenant' => [$world->tenant->id]])->assertSuccessful();
    $sent = count(Http::recorded());
    expect(AnthropicInsightAllocation::query()->sole()->consumed_at)->not->toBeNull()
        ->and(DB::table('anthropic_budget_holds')->where('operation', 'like', 'insight:'.$world->brand->id.':2026-11-01:overage:%')->whereNull('settled_at')->count())->toBe(1)
        ->and(UsageEvent::query()->where('kind', 'pdf')->count())->toBe(0);

    $this->artisan('ekko:send-reports', ['--tenant' => [$world->tenant->id]])->assertSuccessful();
    expect(count(Http::recorded()))->toBe($sent);
    clearReportBudget();
});

test('a second monthly schedule is sent when this run already owns the unknown insight', function () {
    Storage::fake(config()->string('ekko.reports.disk'));
    Mail::fake();
    config(['services.anthropic.key' => 'test-key', 'ekko.anthropic.monthly_pool_usd' => '100']);
    $attempts = 0;
    Http::fake(function () use (&$attempts): void {
        $attempts++;
        throw new ConnectionException('timed out');
    });
    Pdf::swap(new class extends FakePdfBuilder
    {
        public function save(string $path): self
        {
            Storage::disk(config()->string('ekko.reports.disk'))->put($path, '%PDF-fake');

            return $this;
        }
    });
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
        'locale' => 'nb',
        'recipients' => ['nb@example.test'],
    ]);
    ReportSchedule::factory()->create([
        'brand_id' => $world->brand->id,
        'cadence' => 'monthly',
        'locale' => 'sv',
        'recipients' => ['sv@example.test'],
    ]);
    AnthropicInsightAllocation::query()->create([
        'brand_id' => $world->brand->id,
        'cycle_start' => '2026-11-01',
        'cost_usd' => '0.05000000',
    ]);

    $this->artisan('ekko:send-reports', ['--tenant' => [$world->tenant->id]])->assertSuccessful();
    expect(Report::query()->whereNotNull('pdf_uri')->count())->toBe(2)
        ->and($attempts)->toBe(1);
    Mail::assertSent(ReportMail::class, fn (ReportMail $mail): bool => $mail->hasTo('nb@example.test'));
    Mail::assertSent(ReportMail::class, fn (ReportMail $mail): bool => $mail->hasTo('sv@example.test'));
    clearReportBudget();
});

test('a report waits when another run already owns the insight claim', function () {
    Storage::fake(config()->string('ekko.reports.disk'));
    Mail::fake();
    config(['services.anthropic.key' => 'test-key', 'ekko.anthropic.monthly_pool_usd' => '100']);
    Http::fake();
    Pdf::swap(new class extends FakePdfBuilder
    {
        public function save(string $path): self
        {
            Storage::disk(config()->string('ekko.reports.disk'))->put($path, '%PDF-fake');

            return $this;
        }
    });
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
    $schedule = ReportSchedule::factory()->create([
        'brand_id' => $world->brand->id,
        'cadence' => 'monthly',
        'locale' => 'nb',
        'recipients' => ['reports@example.test'],
    ]);
    $weekly = ReportSchedule::factory()->create([
        'brand_id' => $world->brand->id,
        'cadence' => 'weekly',
        'locale' => 'nb',
        'recipients' => ['week@example.test'],
    ]);
    AnthropicInsightAllocation::query()->create([
        'brand_id' => $world->brand->id,
        'cycle_start' => '2026-11-01',
        'cost_usd' => '0.05000000',
    ]);
    $rls = app(TenantRls::class);
    $admission = $rls->run([$world->tenant->id], fn () => app(InsightWriter::class)->admit($world->brand));
    expect($admission)->not->toBeNull();

    $this->artisan('ekko:send-reports', ['--tenant' => [$world->tenant->id]])->assertFailed();
    expect(Report::query()->whereNotNull('pdf_uri')->pluck('report_schedule_id')->all())->toBe([$weekly->id]);
    Http::assertNothingSent();

    Storage::disk(config()->string('ekko.reports.disk'))->put(
        'reports/'.$world->tenant->id.'/'.$schedule->id.'-2026-10-01.insight.json',
        json_encode([
            'text' => 'Beholdt tekst',
            'cost' => '0.01000000',
            'operation' => 'insight:'.$world->brand->id.':2026-11-01',
            'overage' => null,
        ], JSON_THROW_ON_ERROR),
    );

    $this->artisan('ekko:send-reports', ['--tenant' => [$world->tenant->id]])->assertSuccessful();
    expect(Report::query()->count())->toBe(2);
    Http::assertNothingSent();
    clearReportBudget();
});

test('an expired insight claim still produces the report and does not call sonnet again', function () {
    Storage::fake(config()->string('ekko.reports.disk'));
    Mail::fake();
    config(['services.anthropic.key' => 'test-key', 'ekko.anthropic.monthly_pool_usd' => '100']);
    Http::fake();
    Pdf::swap(new class extends FakePdfBuilder
    {
        public function save(string $path): self
        {
            Storage::disk(config()->string('ekko.reports.disk'))->put($path, '%PDF-fake');

            return $this;
        }
    });
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
        'locale' => 'nb',
        'recipients' => ['reports@example.test'],
    ]);
    AnthropicInsightAllocation::query()->create([
        'brand_id' => $world->brand->id,
        'cycle_start' => '2026-11-01',
        'cost_usd' => '0.05000000',
    ]);
    $admission = app(TenantRls::class)->run([$world->tenant->id], fn () => app(InsightWriter::class)->admit($world->brand));
    expect($admission)->not->toBeNull();
    $this->travelTo('2026-11-17 12:20:00');

    $this->artisan('ekko:send-reports', ['--tenant' => [$world->tenant->id]])->assertSuccessful();
    expect(Report::query()->count())->toBe(1)
        ->and(AnthropicInsightAllocation::query()->sole()->consumed_at)->not->toBeNull()
        ->and(DB::table('anthropic_budget_holds')->where('operation', 'like', 'insight:'.$world->brand->id.':2026-11-01:overage:%')->whereNull('settled_at')->count())->toBe(1);
    Http::assertNothingSent();
    clearReportBudget();
});

test('a second monthly schedule is sent after the insight allocation is already used', function () {
    Storage::fake(config()->string('ekko.reports.disk'));
    Mail::fake();
    config(['services.anthropic.key' => 'test-key', 'ekko.anthropic.monthly_pool_usd' => '100']);
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'En tekst.']],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 40],
        ]),
    ]);
    Pdf::swap(new class extends FakePdfBuilder
    {
        public function save(string $path): self
        {
            Storage::disk(config()->string('ekko.reports.disk'))->put($path, '%PDF-fake');

            return $this;
        }
    });
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
        'locale' => 'nb',
        'recipients' => ['nb@example.test'],
    ]);
    ReportSchedule::factory()->create([
        'brand_id' => $world->brand->id,
        'cadence' => 'monthly',
        'locale' => 'sv',
        'recipients' => ['sv@example.test'],
    ]);
    AnthropicInsightAllocation::query()->create([
        'brand_id' => $world->brand->id,
        'cycle_start' => '2026-11-01',
        'cost_usd' => '0.05000000',
    ]);

    $this->artisan('ekko:send-reports', ['--tenant' => [$world->tenant->id]])->assertSuccessful();
    expect(Report::query()->count())->toBe(2);
    Http::assertSentCount(1);
    Mail::assertSent(ReportMail::class, fn (ReportMail $mail): bool => $mail->hasTo('nb@example.test'));
    Mail::assertSent(ReportMail::class, fn (ReportMail $mail): bool => $mail->hasTo('sv@example.test'));
    clearReportBudget();
});

test('a failed recipient is skipped on the next delivery', function () {
    Storage::fake(config()->string('ekko.reports.disk'));
    config(['services.anthropic.key' => '']);
    Pdf::swap(new class extends FakePdfBuilder
    {
        public function save(string $path): self
        {
            Storage::disk(config()->string('ekko.reports.disk'))->put($path, '%PDF-fake');

            return $this;
        }
    });
    $this->travelTo('2026-11-17 12:00:00');
    $world = MetricsWorld::create('UTC');
    app(TenantContext::class)->set($world->tenant->id);
    ReportSchedule::factory()->create([
        'brand_id' => $world->brand->id,
        'cadence' => 'weekly',
        'locale' => 'nb',
        'recipients' => ['one@example.test', 'two@example.test'],
    ]);
    $failSecond = true;
    $seen = 0;
    Event::listen(MessageSending::class, function () use (&$seen, &$failSecond): void {
        $seen++;
        if ($failSecond && $seen === 2) {
            throw new RuntimeException('second recipient failed');
        }
    });

    $this->artisan('ekko:send-reports', ['--tenant' => [$world->tenant->id]])->assertFailed();
    $report = Report::query()->sole();
    expect($report->sent_to)->toBe(['one@example.test'])->and($report->sent_at)->toBeNull();

    $failSecond = false;
    $this->artisan('ekko:send-reports', ['--tenant' => [$world->tenant->id]])->assertSuccessful();
    expect($report->fresh()->sent_to)->toBe(['one@example.test', 'two@example.test'])
        ->and($report->fresh()->sent_at)->not->toBeNull();
    $transport = Mail::mailer()->getSymfonyTransport();
    expect($transport)->toBeInstanceOf(ArrayTransport::class);
    $addresses = $transport->messages()->map(function ($message): string {
        $recipient = $message->getEnvelope()->getRecipients()[0] ?? null;

        return $recipient === null ? '' : $recipient->getAddress();
    })->all();
    expect($addresses)->toBe(['one@example.test', 'two@example.test']);
});

test('a failed render is stored on the next scheduled run', function () {
    Storage::fake(config()->string('ekko.reports.disk'));
    Mail::fake();
    config(['services.anthropic.key' => '']);
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
    $this->travelTo('2026-11-17 12:00:00');
    $world = MetricsWorld::create('UTC');
    app(TenantContext::class)->set($world->tenant->id);
    ReportSchedule::factory()->create([
        'brand_id' => $world->brand->id,
        'cadence' => 'weekly',
        'locale' => 'nb',
        'recipients' => ['week@example.test'],
    ]);

    $this->artisan('ekko:send-reports', ['--tenant' => [$world->tenant->id]])->assertFailed();
    expect(Report::query()->sole()->pdf_uri)->toBeNull();

    $this->travelTo('2026-11-24 12:00:00');
    $this->artisan('ekko:send-reports', ['--tenant' => [$world->tenant->id]])->assertSuccessful();
    expect(Report::query()->orderBy('period_start')->pluck('pdf_uri')->all())->each->not->toBeNull()
        ->and(Report::query()->count())->toBe(2);
});

test('a failed recipient is delivered on the next scheduled run', function () {
    Storage::fake(config()->string('ekko.reports.disk'));
    config(['services.anthropic.key' => '']);
    Pdf::swap(new class extends FakePdfBuilder
    {
        public function save(string $path): self
        {
            Storage::disk(config()->string('ekko.reports.disk'))->put($path, '%PDF-fake');

            return $this;
        }
    });
    $this->travelTo('2026-11-17 12:00:00');
    $world = MetricsWorld::create('UTC');
    app(TenantContext::class)->set($world->tenant->id);
    ReportSchedule::factory()->create([
        'brand_id' => $world->brand->id,
        'cadence' => 'weekly',
        'locale' => 'nb',
        'recipients' => ['one@example.test', 'two@example.test'],
    ]);
    $failSecond = true;
    $seen = 0;
    Event::listen(MessageSending::class, function () use (&$seen, &$failSecond): void {
        $seen++;
        if ($failSecond && $seen === 2) {
            throw new RuntimeException('second recipient failed');
        }
    });

    $this->artisan('ekko:send-reports', ['--tenant' => [$world->tenant->id]])->assertFailed();
    $first = Report::query()->sole();
    expect($first->sent_to)->toBe(['one@example.test'])->and($first->sent_at)->toBeNull();

    $failSecond = false;
    $this->travelTo('2026-11-24 12:00:00');
    $this->artisan('ekko:send-reports', ['--tenant' => [$world->tenant->id]])->assertSuccessful();

    expect($first->fresh()->sent_to)->toBe(['one@example.test', 'two@example.test'])
        ->and($first->fresh()->sent_at)->not->toBeNull()
        ->and(Report::query()->count())->toBe(2);
    $transport = Mail::mailer()->getSymfonyTransport();
    expect($transport)->toBeInstanceOf(ArrayTransport::class);
    $addresses = $transport->messages()->map(function ($message): string {
        $recipient = $message->getEnvelope()->getRecipients()[0] ?? null;

        return $recipient === null ? '' : $recipient->getAddress();
    })->all();
    expect($addresses)->toBe(['one@example.test', 'two@example.test', 'one@example.test', 'two@example.test']);
});

test('a missing insight draft is absent instead of a storage error', function () {
    config(['filesystems.disks.reports_missing' => [
        'driver' => 'local',
        'root' => storage_path('app/private'),
        'throw' => true,
    ]]);

    expect(app(InsightWriter::class)->remembered('reports_missing', 'reports/missing.insight.json'))->toBeNull();
});

test('a busy tenant lock is skipped without failing the command', function () {
    Storage::fake(config()->string('ekko.reports.disk'));
    Mail::fake();
    config(['services.anthropic.key' => '']);
    Pdf::swap(new class extends FakePdfBuilder
    {
        public function save(string $path): self
        {
            Storage::disk(config()->string('ekko.reports.disk'))->put($path, '%PDF-fake');

            return $this;
        }
    });
    $this->travelTo('2026-11-17 12:00:00');
    $world = MetricsWorld::create('UTC');
    app(TenantContext::class)->set($world->tenant->id);
    ReportSchedule::factory()->create([
        'brand_id' => $world->brand->id,
        'cadence' => 'weekly',
        'locale' => 'nb',
        'recipients' => ['week@example.test'],
    ]);
    $skipped = false;
    Log::listen(function (MessageLogged $logged) use (&$skipped): void {
        if ($logged->level === 'info' && str_contains($logged->message, 'another run holds the tenant lock')) {
            $skipped = true;
        }
    });
    $lock = Cache::lock('reports:tenant:'.$world->tenant->id, 900);
    expect($lock->get())->toBeTrue();

    try {
        $this->artisan('ekko:send-reports', ['--tenant' => [$world->tenant->id]])->assertSuccessful();
    } finally {
        $lock->release();
    }

    expect(Report::query()->count())->toBe(0)->and($skipped)->toBeTrue();
});

test('a known insight is booked when the draft cannot be stored', function () {
    $root = storage_path('framework/testing/reports-guard');
    if (! is_dir($root)) {
        mkdir($root, 0777, true);
    }
    Storage::extend('insight_guard', function () use ($root) {
        $adapter = new LocalFilesystemAdapter($root);
        $flysystem = new class($adapter) extends Flysystem
        {
            public function write(string $location, string $contents, array $config = []): void
            {
                if (str_ends_with($location, '.insight.json')) {
                    throw UnableToWriteFile::atLocation($location, 'draft storage failed');
                }

                parent::write($location, $contents, $config);
            }
        };

        return new FilesystemAdapter($flysystem, $adapter, ['throw' => true]);
    });
    config([
        'filesystems.disks.insight_guard' => ['driver' => 'insight_guard', 'throw' => true],
        'ekko.reports.disk' => 'insight_guard',
    ]);
    Mail::fake();
    config(['services.anthropic.key' => 'test-key', 'ekko.anthropic.monthly_pool_usd' => '100']);
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'En tekst.']],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 40],
        ]),
    ]);
    Pdf::swap(new class extends FakePdfBuilder
    {
        public function save(string $path): self
        {
            Storage::disk(config()->string('ekko.reports.disk'))->put($path, '%PDF-fake');

            return $this;
        }
    });
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
        'locale' => 'nb',
        'recipients' => ['reports@example.test'],
    ]);
    AnthropicInsightAllocation::query()->create([
        'brand_id' => $world->brand->id,
        'cycle_start' => '2026-11-01',
        'cost_usd' => '0.05000000',
    ]);

    $this->artisan('ekko:send-reports', ['--tenant' => [$world->tenant->id]])->assertSuccessful();
    expect(Report::query()->count())->toBe(1)
        ->and(UsageEvent::query()->where('anthropic_operation', 'insight:'.$world->brand->id.':2026-11-01')->count())->toBe(1);
    Http::assertSentCount(1);
    clearReportBudget();
});

test('a known insight is booked when pricing throws after the response', function () {
    Storage::fake(config()->string('ekko.reports.disk'));
    Mail::fake();
    config(['services.anthropic.key' => 'test-key', 'ekko.anthropic.monthly_pool_usd' => '100']);
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'En tekst.']],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 40],
        ]),
    ]);
    Pdf::swap(new class extends FakePdfBuilder
    {
        public function save(string $path): self
        {
            Storage::disk(config()->string('ekko.reports.disk'))->put($path, '%PDF-fake');

            return $this;
        }
    });
    $calls = 0;
    app()->instance(InsightPrice::class, new class(app(EnginePricing::class)) extends InsightPrice
    {
        public int $calls = 0;

        public function amount(EnginePrice $price, EngineResult $result): string
        {
            $this->calls++;
            throw new RuntimeException('pricing failed after a known response');
        }
    });
    $calls = app(InsightPrice::class);
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
        'locale' => 'nb',
        'recipients' => ['reports@example.test'],
    ]);
    AnthropicInsightAllocation::query()->create([
        'brand_id' => $world->brand->id,
        'cycle_start' => '2026-11-01',
        'cost_usd' => '0.05000000',
    ]);

    $this->artisan('ekko:send-reports', ['--tenant' => [$world->tenant->id]])->assertSuccessful();
    expect($calls->calls)->toBe(1)
        ->and(UsageEvent::query()->where('anthropic_operation', 'insight:'.$world->brand->id.':2026-11-01')->value('cost_usd'))->toBe('0.15000000');
    clearReportBudget();
});

test('a missing logo does not stop the report', function () {
    $root = storage_path('framework/testing/reports-no-logo');
    if (! is_dir($root)) {
        mkdir($root, 0777, true);
    }
    config([
        'filesystems.disks.reports_no_logo' => [
            'driver' => 'local',
            'root' => $root,
            'throw' => true,
        ],
        'ekko.reports.disk' => 'reports_no_logo',
        'services.anthropic.key' => '',
    ]);
    Mail::fake();
    Pdf::swap(new class extends FakePdfBuilder
    {
        public function save(string $path): self
        {
            Storage::disk(config()->string('ekko.reports.disk'))->put($path, '%PDF-fake');

            return $this;
        }
    });
    $this->travelTo('2026-11-17 12:00:00');
    $world = MetricsWorld::create('UTC');
    $world->tenant->forceFill([
        'branding' => ['logo' => 'branding/'.$world->tenant->id.'/logo.png'],
    ])->save();
    app(TenantContext::class)->set($world->tenant->id);
    ReportSchedule::factory()->create([
        'brand_id' => $world->brand->id,
        'cadence' => 'weekly',
        'locale' => 'nb',
        'recipients' => ['week@example.test'],
    ]);

    $this->artisan('ekko:send-reports', ['--tenant' => [$world->tenant->id]])->assertSuccessful();
    expect(Report::query()->count())->toBe(1);
});

test('a monthly report is compared with the previous calendar month', function () {
    expect(ReportWindow::compared('monthly', '2026-10-01', '2026-10-31'))->toBe(['2026-09-01', '2026-09-30'])
        ->and(ReportWindow::compared('monthly', '2026-03-01', '2026-03-31'))->toBe(['2026-02-01', '2026-02-28'])
        ->and(ReportWindow::compared('weekly', '2026-11-09', '2026-11-15'))->toBe(['2026-11-02', '2026-11-08']);
});

test('a report shows the sample size and fades a small sample', function () {
    $world = MetricsWorld::create('UTC');
    app(TenantContext::class)->set($world->tenant->id);
    $schedule = ReportSchedule::factory()->create([
        'brand_id' => $world->brand->id,
        'cadence' => 'monthly',
        'locale' => 'nb',
        'recipients' => ['reports@example.test'],
    ]);
    app()->setLocale('nb');
    $html = view('reports.pdf', app(ReportPublisher::class)->viewData(
        $world->tenant,
        $schedule,
        '2026-10-01',
        '2026-10-31',
        null,
    ))->render();

    expect($html)->toContain('n 0')->toContain('class="value low"');
});

function clearReportBudget(): void
{
    $budget = Account::independent()->db();
    $budget->statement("SET lock_timeout = '2s'");
    $budget->table('anthropic_budget_holds')->delete();
    $budget->table('anthropic_budget_cycles')->delete();
    DB::purge('anthropic_budget');
}

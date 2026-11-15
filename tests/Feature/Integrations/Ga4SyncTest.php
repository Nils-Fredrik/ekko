<?php

use App\Enums\TenantStatus;
use App\Models\AiReferralDaily;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Integration;
use App\Models\Team;
use App\Tenancy\TenantContext;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

const GA4_REPORT_ENDPOINT = 'https://analyticsdata.googleapis.com/v1beta/properties/123456789:runReport';
const GA4_TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

/** @return array<string, mixed> */
function ga4Fixture(string $name): array
{
    return json_decode(
        file_get_contents(base_path("tests/Fixtures/Integrations/Ga4/{$name}.json")),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

function seededGa4Integration(Team $tenant): Integration
{
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    openssl_pkey_export($key, $privateKey);

    return app(TenantContext::class)->run($tenant->id, fn () => Integration::factory()->create([
        'config' => [
            'property_id' => '123456789',
            'service_account' => [
                'client_email' => 'ga4-fixture@example.test',
                'private_key' => $privateKey,
                'token_uri' => 'https://example.test/untrusted-token-endpoint',
            ],
        ],
    ]));
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-15 04:00:00', 'Europe/Oslo'));
    $this->tenant = Team::factory()->tenant()->create();
    $this->integration = seededGa4Integration($this->tenant);

    Http::preventStrayRequests();
});

test('the command requests only configured sources and replaces the expected daily aggregates', function () {
    $pausedTenant = Team::factory()->tenant()->create();
    $pausedTenant->forceFill(['status' => TenantStatus::Paused])->save();
    $pausedIntegration = seededGa4Integration($pausedTenant);

    Http::fake([
        GA4_TOKEN_ENDPOINT => Http::response(['access_token' => 'fake-ga4-token']),
        GA4_REPORT_ENDPOINT => Http::response(ga4Fixture('daily-report')),
    ]);
    $brandId = $this->integration->brand_id;

    [$existing, $stale, $old] = app(TenantContext::class)->run($this->tenant->id, fn () => [
        AiReferralDaily::factory()->create(['brand_id' => $brandId, 'date' => '2026-11-14']),
        AiReferralDaily::factory()->create(['brand_id' => $brandId, 'date' => '2026-11-12']),
        AiReferralDaily::factory()->create(['brand_id' => $brandId, 'date' => '2026-11-11']),
    ]);

    $this->artisan('ekko:sync-ga4')->assertSuccessful();
    $this->artisan('ekko:sync-ga4')->assertSuccessful();

    Http::assertSentCount(4);
    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== GA4_TOKEN_ENDPOINT) {
            return false;
        }

        parse_str($request->body(), $form);
        $claims = json_decode(base64_decode(strtr(explode('.', $form['assertion'])[1], '-_', '+/')), true);

        return $form['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
            && $claims['iss'] === 'ga4-fixture@example.test'
            && $claims['aud'] === GA4_TOKEN_ENDPOINT
            && $claims['scope'] === 'https://www.googleapis.com/auth/analytics.readonly';
    });
    Http::assertSent(fn (Request $request): bool => $request->url() === GA4_REPORT_ENDPOINT
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer fake-ga4-token')
        && $request['dateRanges'] === [['startDate' => '2026-11-12', 'endDate' => '2026-11-14']]
        && $request['dimensions'] === [['name' => 'date'], ['name' => 'sessionSource']]
        && $request['metrics'] === [['name' => 'sessions'], ['name' => 'engagedSessions'], ['name' => 'keyEvents']]
        && $request['dimensionFilter'] === [
            'filter' => [
                'fieldName' => 'sessionSource',
                'inListFilter' => [
                    'values' => [
                        'chatgpt.com', 'chat.openai.com', 'perplexity.ai',
                        'gemini.google.com', 'copilot.microsoft.com', 'claude.ai',
                    ],
                    'caseSensitive' => true,
                ],
            ],
        ]);

    app(TenantContext::class)->run($this->tenant->id, function () use ($existing, $stale, $old, $brandId): void {
        $rows = AiReferralDaily::query()->where('brand_id', $brandId)->orderBy('date')->orderBy('source')->get();

        expect($rows->map(fn (AiReferralDaily $row) => [
            $row->date->toDateString(), $row->source, $row->sessions, $row->engaged_sessions, $row->conversions,
        ])->all())->toBe([
            ['2026-11-11', 'chatgpt.com', 10, 6, '1.0000'],
            ['2026-11-13', 'perplexity.ai', 11, 8, '1.2500'],
            ['2026-11-14', 'chat.openai.com', 5, 3, '0.0000'],
            ['2026-11-14', 'chatgpt.com', 40, 21, '3.0000'],
        ])->and($rows->modelKeys())->toContain($existing->id, $old->id)
            ->and($rows->modelKeys())->not->toContain($stale->id)
            ->and($this->integration->fresh()->status)->toBe('synced')
            ->and($this->integration->fresh()->last_sync_at->equalTo(now()))->toBeTrue();
    });

    app(TenantContext::class)->run($pausedTenant->id, function () use ($pausedIntegration): void {
        expect($pausedIntegration->fresh()->status)->toBe('pending')
            ->and(AiReferralDaily::query()->count())->toBe(0);
    });

    expect(app(TenantContext::class)->check())->toBeFalse();
});

test('access failures on two consecutive tenant days emit one integration alert', function () {
    Http::fake([
        GA4_TOKEN_ENDPOINT => Http::response(['access_token' => 'fake-ga4-token']),
        GA4_REPORT_ENDPOINT => Http::response(ga4Fixture('permission-denied'), 403),
    ]);

    $lastSuccess = now()->subDay();
    app(TenantContext::class)->run($this->tenant->id, function () use ($lastSuccess): void {
        $this->integration->update(['status' => 'synced', 'last_sync_at' => $lastSuccess]);
        AiReferralDaily::factory()->create([
            'brand_id' => $this->integration->brand_id, 'date' => '2026-11-14',
        ]);
    });

    $this->artisan('ekko:sync-ga4')->assertSuccessful();
    $this->artisan('ekko:sync-ga4')->assertSuccessful();
    app(TenantContext::class)->run($this->tenant->id, fn () => expect(Alert::query()->count())->toBe(0));

    $this->travel(1)->day();
    $this->artisan('ekko:sync-ga4')->assertSuccessful();
    $this->artisan('ekko:sync-ga4')->assertSuccessful();

    app(TenantContext::class)->run($this->tenant->id, function () use ($lastSuccess): void {
        $integration = $this->integration->fresh();
        $alert = Alert::query()->sole();

        expect($integration->status)->toBe('error')
            ->and($integration->last_failed_on->toDateString())->toBe('2026-11-16')
            ->and($integration->last_sync_at->equalTo($lastSuccess))->toBeTrue()
            ->and(AiReferralDaily::query()->count())->toBe(1)
            ->and($alert->tenant_id)->toBe($this->tenant->id)
            ->and($alert->payload)->toMatchArray([
                'integration_id' => $integration->id,
                'integration_type' => 'ga4',
                'error_code' => 'access_denied',
            ])
            ->and(AlertRule::query()->findOrFail($alert->alert_rule_id)->type)->toBe('integration_error');
    });
});

test('a successful empty report resets the failure streak and clears old metrics in the report window', function () {
    $report = ga4Fixture('daily-report');
    unset($report['rows'], $report['rowCount']);
    Http::fake([
        GA4_TOKEN_ENDPOINT => Http::response(['access_token' => 'fake-ga4-token']),
        GA4_REPORT_ENDPOINT => Http::sequence()
            ->push(ga4Fixture('permission-denied'), 403)
            ->push($report)
            ->push(ga4Fixture('permission-denied'), 403),
    ]);
    app(TenantContext::class)->run($this->tenant->id, fn () => AiReferralDaily::factory()->create([
        'brand_id' => $this->integration->brand_id, 'date' => '2026-11-14',
    ]));

    $this->artisan('ekko:sync-ga4')->assertSuccessful();
    $this->travel(1)->day();
    $this->artisan('ekko:sync-ga4')->assertSuccessful();

    app(TenantContext::class)->run($this->tenant->id, function (): void {
        expect($this->integration->fresh()->last_failed_on)->toBeNull()
            ->and(AiReferralDaily::query()->count())->toBe(0);
    });

    $this->travel(1)->day();
    $this->artisan('ekko:sync-ga4')->assertSuccessful();

    app(TenantContext::class)->run($this->tenant->id, fn () => expect(Alert::query()->count())->toBe(0));
});

test('incomplete or malformed reports preserve aggregates missing from the response', function () {
    $report = ga4Fixture('daily-report');
    Http::fake([
        GA4_TOKEN_ENDPOINT => Http::response(['access_token' => 'fake-ga4-token']),
        GA4_REPORT_ENDPOINT => Http::sequence()
            ->push([...$report, 'rowCount' => 5])
            ->push([...$report, 'metadata' => ['subjectToThresholding' => true]]),
    ]);
    $stale = app(TenantContext::class)->run($this->tenant->id, fn () => AiReferralDaily::factory()->create([
        'brand_id' => $this->integration->brand_id, 'date' => '2026-11-12',
    ]));

    $this->artisan('ekko:sync-ga4')->assertSuccessful();
    app(TenantContext::class)->run($this->tenant->id, function (): void {
        expect($this->integration->fresh()->status)->toBe('error')
            ->and($this->integration->fresh()->last_sync_at)->toBeNull()
            ->and(AiReferralDaily::query()->count())->toBe(1);
    });

    $this->artisan('ekko:sync-ga4')->assertSuccessful();
    app(TenantContext::class)->run($this->tenant->id, function () use ($stale): void {
        expect($this->integration->fresh()->status)->toBe('synced')
            ->and(AiReferralDaily::query()->count())->toBe(4)
            ->and($stale->fresh()->sessions)->toBe(10);
    });
});

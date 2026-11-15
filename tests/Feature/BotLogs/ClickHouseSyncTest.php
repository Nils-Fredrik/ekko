<?php

use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\BotHitDaily;
use App\Models\Brand;
use App\Models\Integration;
use App\Models\Team;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['ekko.bot_logs.clickhouse_endpoints' => ['https://clickhouse.example.test:8443']]);
    $this->travelTo(now()->setDate(2026, 11, 15)->setTime(4, 0));
});

function clickHouseIntegration(Team $team, string $domain, string $table = 'access_raw'): Integration
{
    return app(TenantContext::class)->run($team->id, function () use ($domain, $table) {
        $brand = Brand::factory()->create(['primary_domain' => $domain]);
        $brand->domains()->create(['domain' => $domain, 'include_subdomains' => true]);

        return Integration::factory()->for($brand)->create([
            'type' => 'clickhouse_logs',
            'config' => [
                'host' => 'clickhouse.example.test:8443',
                'database' => 'logs',
                'table' => $table,
                'user' => 'fixture',
                'password' => 'fixture-password',
            ],
        ]);
    });
}

it('replaces one UTC day with fixture counts for each bot and domain', function () {
    $team = Team::factory()->tenant()->create();
    $integration = clickHouseIntegration($team, 'site.example.test');
    $context = app(TenantContext::class);
    $context->run($team->id, fn () => BotHitDaily::factory()->create([
        'brand_id' => $integration->brand_id,
        'date' => '2026-11-13',
        'domain' => 'site.example.test',
        'bot' => 'gptbot',
        'hits' => 8,
    ]));

    Http::preventStrayRequests();
    Http::fake(['https://clickhouse.example.test:8443*' => Http::sequence()
        ->push(file_get_contents(base_path('tests/Fixtures/bot-logs/clickhouse.jsonl')))
        ->push(file_get_contents(base_path('tests/Fixtures/bot-logs/clickhouse.jsonl')))
        ->push('')]);

    $this->artisan('ekko:sync-bot-hits')->assertSuccessful();
    $this->artisan('ekko:sync-bot-hits --date=2026-11-14')->assertSuccessful();

    Http::assertSent(function (Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('fixture:fixture-password'))
            && str_contains($request->body(), 'FROM `logs`.`access_raw`')
            && $query['param_start'] === '2026-11-14 00:00:00'
            && $query['param_end'] === '2026-11-15 00:00:00'
            && $query['param_domains'] === "['site.example.test']"
            && $query['param_suffixes'] === "['site.example.test']"
            && str_contains($query['param_agents'], "'GPTBot'");
    });

    $context->run($team->id, function () use ($integration) {
        expect(BotHitDaily::query()->whereDate('date', '2026-11-14')->orderBy('bot')->get()
            ->map(fn (BotHitDaily $row) => [$row->domain, $row->bot, $row->hits, $row->unique_paths, $row->top_paths])->all())
            ->toBe([
                ['news.site.example.test', 'claude_searchbot', 1, 1, [['hits' => 1, 'path' => '/']]],
                ['site.example.test', 'gptbot', 3, 2, [['hits' => 2, 'path' => '/shop'], ['hits' => 1, 'path' => '/help']]],
            ]);
        expect($integration->refresh()->status)->toBe('synced')->and($integration->last_sync_at)->not->toBeNull();
    });

    $this->artisan('ekko:sync-bot-hits')->assertSuccessful();
    $context->run($team->id, fn () => expect(BotHitDaily::query()->pluck('hits')->all())->toBe([8]));
});

it('preserves the previous snapshot and continues syncing unrelated tenants on failure', function () {
    $context = app(TenantContext::class);
    $teamA = Team::factory()->tenant()->create();
    $teamB = Team::factory()->tenant()->create();
    $integrationA = clickHouseIntegration($teamA, 'a.example.test');
    $integrationB = clickHouseIntegration($teamB, 'b.example.test');
    $context->run($teamA->id, fn () => BotHitDaily::factory()->create([
        'brand_id' => $integrationA->brand_id,
        'date' => '2026-11-14',
        'domain' => 'a.example.test',
        'hits' => 7,
    ]));

    Http::fake(['https://clickhouse.example.test:8443*' => Http::sequence()
        ->push('{"domain":"b.example.test","bot":"gptbot","hits":2,"unique_paths":1,"top_paths":[[-2,"/"]]}')
        ->push('{"domain":"b.example.test","bot":"gptbot","hits":1,"unique_paths":1,"top_paths":[[-1,"/"]]}')]);

    $this->artisan('ekko:sync-bot-hits')->assertExitCode(1);

    $context->run($teamA->id, function () use ($integrationA) {
        expect($integrationA->refresh()->status)->toBe('error')
            ->and($integrationA->last_sync_at)->toBeNull()
            ->and(BotHitDaily::query()->sole()->hits)->toBe(7);
    });
    $context->run($teamB->id, function () use ($integrationB) {
        expect($integrationB->refresh()->status)->toBe('synced')
            ->and(BotHitDaily::query()->sole()->hits)->toBe(1);
    });
});

it('rejects unsafe table names and unapproved endpoints before making an HTTP request', function () {
    $team = Team::factory()->tenant()->create();
    clickHouseIntegration($team, 'site.example.test', 'access_raw; SELECT 1');
    Http::fake();

    $this->artisan('ekko:sync-bot-hits')->assertExitCode(1);
    config(['ekko.bot_logs.clickhouse_endpoints' => []]);
    $this->artisan('ekko:sync-bot-hits')->assertExitCode(1);
    Http::assertNothingSent();
});

it('discards results when the connection configuration changes during the fetch', function () {
    $team = Team::factory()->tenant()->create();
    $integration = clickHouseIntegration($team, 'site.example.test');
    Http::fake(['https://clickhouse.example.test:8443*' => function () use ($integration) {
        $changed = [...$integration->config, 'table' => 'replacement'];
        Integration::query()->findOrFail($integration->id)->update(['config' => $changed]);

        return Http::response(file_get_contents(base_path('tests/Fixtures/bot-logs/clickhouse.jsonl')));
    }]);

    $this->artisan('ekko:sync-bot-hits')->assertSuccessful();

    app(TenantContext::class)->run($team->id, function () use ($integration) {
        expect($integration->refresh()->status)->toBe('pending')
            ->and($integration->last_sync_at)->toBeNull()
            ->and(BotHitDaily::query()->exists())->toBeFalse();
    });
});

it('alerts once per local failure day after consecutive failures and isolates tenants', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 00:30:00', 'UTC'));
    $teamA = Team::factory()->tenant()->create(['timezone' => 'America/Los_Angeles']);
    $teamB = Team::factory()->tenant()->create();
    $integrationA = clickHouseIntegration($teamA, 'a.example.test');
    $integrationB = clickHouseIntegration($teamB, 'b.example.test');
    $context = app(TenantContext::class);
    $lastSuccess = now()->subDays(2);
    $context->run($teamA->id, function () use ($integrationA, $lastSuccess) {
        $integrationA->update(['last_sync_at' => $lastSuccess]);
        BotHitDaily::factory()->create(['brand_id' => $integrationA->brand_id, 'date' => '2026-11-10', 'hits' => 7]);
    });
    Http::fake(['https://clickhouse.example.test:8443*' => function (Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_contains($query['param_domains'], 'a.example.test')
            ? Http::response('synthetic-provider-error', 503)
            : Http::response('');
    }]);

    $this->artisan('ekko:sync-bot-hits --date=2026-11-10')->assertFailed();
    $this->artisan('ekko:sync-bot-hits --date=2026-11-10')->assertFailed();
    $context->run($teamA->id, function () use ($integrationA) {
        expect($integrationA->fresh()->last_failed_on->toDateString())->toBe('2026-11-14')
            ->and(Alert::query()->count())->toBe(0);
    });
    $this->travel(1)->day();
    $this->artisan('ekko:sync-bot-hits --date=2026-11-10')->assertFailed();
    $this->artisan('ekko:sync-bot-hits --date=2026-11-10')->assertFailed();
    $context->run($teamA->id, function () use ($integrationA, $teamA, $lastSuccess) {
        $integration = $integrationA->fresh();
        $alert = Alert::query()->sole();
        expect($integration->status)->toBe('error')
            ->and($integration->last_failed_on->toDateString())->toBe('2026-11-15')
            ->and($integration->last_sync_at->equalTo($lastSuccess))->toBeTrue()
            ->and(BotHitDaily::query()->sole()->hits)->toBe(7)
            ->and($alert->tenant_id)->toBe($teamA->id)
            ->and($alert->payload)->toEqual([
                'integration_id' => $integrationA->id,
                'integration_type' => 'clickhouse_logs',
                'error_code' => 'sync_failed',
            ]);
        expect(AlertRule::query()->sole()->type)->toBe('integration_error');
    });
    $context->run($teamB->id, function () use ($integrationB) {
        expect($integrationB->fresh()->status)->toBe('synced')
            ->and($integrationB->fresh()->last_failed_on)->toBeNull()
            ->and(Alert::query()->count())->toBe(0)
            ->and(AlertRule::query()->count())->toBe(0);
    });
});

it('breaks the failure streak after a skipped day or successful sync', function () {
    $team = Team::factory()->tenant()->create();
    $integration = clickHouseIntegration($team, 'site.example.test');
    Http::fake(['https://clickhouse.example.test:8443*' => Http::sequence()
        ->push('', 503)->push('', 503)->push('')->push('', 503)->push('', 503)]);
    $this->artisan('ekko:sync-bot-hits')->assertFailed();
    $this->travel(2)->days();
    $this->artisan('ekko:sync-bot-hits')->assertFailed();
    app(TenantContext::class)->run($team->id, fn () => expect(Alert::query()->count())->toBe(0));
    $this->travel(1)->day();
    $this->artisan('ekko:sync-bot-hits')->assertSuccessful();
    app(TenantContext::class)->run($team->id, function () use ($integration) {
        expect($integration->fresh()->status)->toBe('synced')
            ->and($integration->fresh()->last_failed_on)->toBeNull();
    });
    $this->travel(1)->day();
    $this->artisan('ekko:sync-bot-hits')->assertFailed();
    app(TenantContext::class)->run($team->id, fn () => expect(Alert::query()->count())->toBe(0));
    $this->travel(1)->day();
    $this->artisan('ekko:sync-bot-hits')->assertFailed();
    app(TenantContext::class)->run($team->id, fn () => expect(Alert::query()->count())->toBe(1));
});

it('reuses configured alert rules and respects disabled rules', function (bool $brandSpecific, bool $active) {
    $team = Team::factory()->tenant()->create();
    $integration = clickHouseIntegration($team, 'site.example.test');
    $rule = app(TenantContext::class)->run($team->id, fn () => AlertRule::factory()->create([
        'brand_id' => $brandSpecific ? $integration->brand_id : null,
        'type' => 'integration_error', 'is_active' => $active,
    ]));
    Http::fake(['https://clickhouse.example.test:8443*' => Http::response('', 403)]);
    $this->artisan('ekko:sync-bot-hits')->assertFailed();
    $this->travel(1)->day();
    $this->artisan('ekko:sync-bot-hits')->assertFailed();
    app(TenantContext::class)->run($team->id, function () use ($rule, $active) {
        expect(AlertRule::query()->count())->toBe(1)
            ->and(Alert::query()->count())->toBe($active ? 1 : 0);
        if ($active) {
            expect(Alert::query()->sole()->alert_rule_id)->toBe($rule->id);
        }
    });
})->with([[true, true], [false, true], [true, false], [false, false]]);

it('records failures without re-reading broken encrypted configuration', function () {
    $team = Team::factory()->tenant()->create();
    $integration = clickHouseIntegration($team, 'site.example.test');
    app(TenantContext::class)->run($team->id, fn () => Integration::query()->whereKey($integration->id)
        ->update(['config' => json_encode('invalid-ciphertext')]));
    Http::fake();
    $this->artisan('ekko:sync-bot-hits')->assertFailed();
    $this->travel(1)->day();
    $this->artisan('ekko:sync-bot-hits')->assertFailed();
    Http::assertNothingSent();
    app(TenantContext::class)->run($team->id, fn () => expect(Alert::query()->sole()->payload['integration_id'])->toBe($integration->id));
});

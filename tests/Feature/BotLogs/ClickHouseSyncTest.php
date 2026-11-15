<?php

use App\Models\BotHitDaily;
use App\Models\Brand;
use App\Models\Integration;
use App\Models\Team;
use App\Tenancy\TenantContext;
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
        expect($integration->refresh()->status)->toBe('active')->and($integration->last_sync_at)->not->toBeNull();
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
        expect($integrationB->refresh()->status)->toBe('active')
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

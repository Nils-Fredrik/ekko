<?php

use App\Engines\Credentials\ApiKeyProvider;
use App\Models\Activity;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Brand;
use App\Models\BrandAlias;
use App\Models\Integration;
use App\Models\MarketEngine;
use App\Models\Report;
use App\Models\ReportSchedule;
use App\Models\Team;
use App\Models\TenantApiKey;
use App\Models\UsageEvent;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Metrics\MetricsWorld;

test('brand settings save aliases domains markets and engines and keep a regex alias', function () {
    $world = MetricsWorld::create();
    $engine = MarketEngine::factory()->create(['market_id' => $world->market->id, 'engine_key' => $world->engine->key]);
    BrandAlias::factory()->create(['brand_id' => $world->brand->id, 'alias' => 'fjord', 'match_mode' => 'regex']);
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);

    $this->actingAs($owner)->put(route('brands.settings.update', $world->brand), [
        'name' => 'Fjordkaffe AS',
        'primary_domain' => 'Fjordkaffe.example',
        'aliases' => [['alias' => 'Fjord', 'match_mode' => 'exact_word']],
        'domains' => [['domain' => 'Shop.Fjordkaffe.example', 'include_subdomains' => true]],
        'markets' => [['id' => $world->market->id, 'location_label' => 'Oslo', 'is_active' => false]],
        'engines' => [['id' => $engine->id, 'frequency' => 'weekly', 'prompt_scope' => 'core', 'repetitions' => 3]],
    ])->assertRedirect();

    $brand = $world->brand->fresh();
    expect($brand->name)->toBe('Fjordkaffe AS')
        ->and($brand->primary_domain)->toBe('fjordkaffe.example')
        ->and($brand->aliases()->where('match_mode', 'regex')->value('alias'))->toBe('fjord')
        ->and($brand->aliases()->where('match_mode', 'exact_word')->value('alias'))->toBe('Fjord')
        ->and($brand->domains()->value('domain'))->toBe('shop.fjordkaffe.example')
        ->and($brand->domains()->firstOrFail()->include_subdomains)->toBeTrue()
        ->and($world->market->fresh()->location_label)->toBe('Oslo')
        ->and($world->market->fresh()->is_active)->toBeFalse()
        ->and($engine->fresh()->frequency)->toBe('weekly')
        ->and($engine->fresh()->prompt_scope)->toBe('core')
        ->and($engine->fresh()->repetitions)->toBe(3);
});

test('two engines in one market cannot share a scope, and a blank threshold uses the default', function () {
    $world = MetricsWorld::create();
    $all = MarketEngine::factory()->create([
        'market_id' => $world->market->id,
        'engine_key' => $world->engine->key,
        'prompt_scope' => 'all',
        'frequency' => 'daily',
    ]);
    $core = MarketEngine::factory()->create([
        'market_id' => $world->market->id,
        'engine_key' => $world->engine->key,
        'prompt_scope' => 'core',
        'frequency' => 'daily',
    ]);
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);
    $original = $world->brand->name;

    $this->actingAs($owner)
        ->from(route('brands.settings', $world->brand))
        ->put(route('brands.settings.update', $world->brand), [
            'name' => 'Skal ikke lagres',
            'engines' => [
                ['id' => $all->id, 'frequency' => 'weekly', 'prompt_scope' => 'core', 'repetitions' => 1],
                ['id' => $core->id, 'frequency' => 'daily', 'prompt_scope' => 'core', 'repetitions' => 1],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('engines.1.prompt_scope');

    expect($world->brand->fresh()->name)->toBe($original)
        ->and($all->fresh()->prompt_scope)->toBe('all')
        ->and($all->fresh()->frequency)->toBe('daily');

    $this->actingAs($owner)
        ->put(route('brands.settings.update', $world->brand), [
            'name' => $original,
            'engines' => [
                ['id' => $all->id, 'frequency' => 'weekly', 'prompt_scope' => 'core', 'repetitions' => 2],
                ['id' => $core->id, 'frequency' => 'monthly', 'prompt_scope' => 'all', 'repetitions' => 3],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $swapped = MarketEngine::query()
        ->where('market_id', $world->market->id)
        ->where('engine_key', $world->engine->key)
        ->get()
        ->keyBy('prompt_scope');
    expect($swapped)->toHaveCount(2)
        ->and($swapped['core']->frequency)->toBe('weekly')
        ->and($swapped['core']->repetitions)->toBe(2)
        ->and($swapped['all']->frequency)->toBe('monthly')
        ->and($swapped['all']->repetitions)->toBe(3);

    $this->actingAs($owner)->post(route('alerts.rules.store'), [
        'brand_id' => $world->brand->id,
        'type' => 'competitor_takeover',
        'is_active' => true,
    ])->assertRedirect();
    $rule = AlertRule::query()->where('type', 'competitor_takeover')->firstOrFail();
    expect((float) $rule->threshold)->toBe(50.0)
        ->and($rule->channels)->toBe(config('ekko.alerts.default_channels'));

    $rule->forceFill(['threshold' => null])->save();
    $this->actingAs($owner)->put(route('alerts.rules.update', $rule), ['is_active' => true])->assertRedirect();
    expect((float) $rule->fresh()->threshold)->toBe(50.0);

    $this->actingAs($owner)->post(route('alerts.rules.store'), [
        'brand_id' => $world->brand->id,
        'type' => 'new_competitor',
        'threshold' => '0',
        'is_active' => true,
    ])->assertRedirect();
    expect((float) AlertRule::query()->where('type', 'new_competitor')->firstOrFail()->threshold)->toBe(0.0);
});

test('brand settings show validation errors and refuse a reader or another tenant', function () {
    $world = MetricsWorld::create();
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);
    $reader = User::factory()->create();
    $world->tenant->users()->attach($reader, ['role' => 'reader']);
    $reader->switchTeam($world->tenant);
    $foreign = app(TenantContext::class)->run(
        Team::factory()->tenant()->create()->id,
        fn () => Brand::factory()->create()->id,
    );

    $this->actingAs($owner)
        ->from(route('brands.settings', $world->brand))
        ->put(route('brands.settings.update', $world->brand), ['primary_domain' => 'not a domain'])
        ->assertRedirect()
        ->assertSessionHasErrors('primary_domain');

    $this->actingAs($reader)->put(route('brands.settings.update', $world->brand), ['name' => 'Nope'])->assertForbidden();
    $this->actingAs($owner)->get('/brands/'.$foreign.'/settings')->assertNotFound();
});

test('a clickhouse host must be on the allowlist and the stored config stays off the page', function () {
    $world = MetricsWorld::create();
    config(['ekko.bot_logs.clickhouse_endpoints' => ['https://logs.example.test']]);
    $integration = Integration::factory()->create([
        'brand_id' => $world->brand->id,
        'type' => 'clickhouse_logs',
        'config' => ['host' => 'https://old.example.test', 'password' => 'clickhouse-secret-value', 'database' => 'logs', 'table' => 'hits'],
    ]);
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);

    $this->actingAs($owner)->get(route('brands.settings', $world->brand))
        ->assertOk()
        ->assertDontSee('clickhouse-secret-value')
        ->assertDontSee('https://old.example.test');

    $this->actingAs($owner)
        ->from(route('brands.settings', $world->brand))
        ->put(route('brands.settings.update', $world->brand), ['clickhouse_host' => 'https://evil.example.test'])
        ->assertRedirect()
        ->assertSessionHasErrors('clickhouse_host');

    $this->actingAs($owner)->put(route('brands.settings.update', $world->brand), [
        'clickhouse_host' => 'https://logs.example.test',
    ])->assertRedirect();

    expect($integration->fresh()->config['host'])->toBe('https://logs.example.test')
        ->and($integration->fresh()->config['password'])->toBe('clickhouse-secret-value');
});

test('alerts can be acknowledged and a rule rejects an invalid threshold', function () {
    $world = MetricsWorld::create();
    $rule = AlertRule::factory()->create(['brand_id' => $world->brand->id, 'type' => 'visibility_drop', 'threshold' => '10']);
    $alert = Alert::factory()->create(['alert_rule_id' => $rule->id, 'subject' => 'Quota']);
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);

    $this->actingAs($owner)->get(route('alerts.index'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('Alerts/Index')
        ->where('alerts.0.subject', 'Quota')
        ->where('canManage', true));

    $this->actingAs($owner)->post(route('alerts.acknowledge', $alert))->assertRedirect();
    expect($alert->fresh()->acknowledged_by)->toBe($owner->id);

    $this->actingAs($owner)
        ->from(route('alerts.index'))
        ->put(route('alerts.rules.update', $rule), ['threshold' => '250', 'is_active' => true])
        ->assertRedirect()
        ->assertSessionHasErrors('threshold');

    $this->actingAs($owner)->post(route('alerts.rules.store'), [
        'brand_id' => $world->brand->id,
        'type' => 'quota',
        'threshold' => '80',
        'is_active' => true,
    ])->assertRedirect();
    expect(AlertRule::query()->where('type', 'quota')->whereNull('brand_id')->count())->toBe(1);

    $this->actingAs($owner)
        ->from(route('alerts.index'))
        ->post(route('alerts.rules.store'), [
            'type' => 'quota',
            'is_active' => true,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('type');

    $this->actingAs($owner)
        ->from(route('alerts.index'))
        ->post(route('alerts.rules.store'), [
            'brand_id' => $world->brand->id,
            'type' => 'visibility_drop',
            'is_active' => true,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('type');
});

test('report schedules validate recipients and downloads stay inside the tenant prefix', function () {
    $world = MetricsWorld::create();
    Storage::fake('local');
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);
    $schedule = ReportSchedule::factory()->create(['brand_id' => $world->brand->id]);
    $report = Report::factory()->create(['report_schedule_id' => $schedule->id]);
    $path = 'reports/'.$world->tenant->id.'/'.$report->id.'.pdf';
    $report->forceFill(['pdf_uri' => $path])->save();
    Storage::disk('local')->put($path, "%PDF-1.4\nreport");

    $this->actingAs($owner)->get(route('reports.index'))->assertOk()->assertInertia(fn ($page) => $page
        ->where('reportLocales.0', 'nb')
        ->where('locales.0.code', 'nb'));

    $this->actingAs($owner)
        ->from(route('reports.index'))
        ->put(route('reports.schedules.update', $schedule), [
            'cadence' => 'monthly',
            'recipients' => ['owner@example.test', 'not-an-email'],
            'locale' => 'nb',
            'branding' => 'tenant',
        ])->assertRedirect()->assertSessionHasErrors('recipients.1');

    $this->actingAs($owner)->put(route('reports.schedules.update', $schedule), [
        'cadence' => 'monthly',
        'recipients' => ['owner@example.test'],
        'locale' => 'sv',
        'branding' => 'partner',
    ])->assertRedirect();
    expect($schedule->fresh()->locale)->toBe('sv');

    $this->actingAs($owner)->get(route('reports.download', $report))->assertOk()->assertDownload('report.pdf');

    $report->forceFill(['pdf_uri' => 'reports/'.$world->tenant->id.'/../../.env'])->save();
    $this->actingAs($owner)->get(route('reports.download', $report))->assertNotFound();
});

test('usage shows the month against the quota and is hidden from a reader', function () {
    $world = MetricsWorld::create();
    UsageEvent::factory()->create([
        'engine_key' => $world->engine->key,
        'occurred_at' => now(),
        'calls' => 4,
        'cost_usd' => '1.50000000',
        'billed_to' => 'platform',
    ]);
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);
    $reader = User::factory()->create();
    $world->tenant->users()->attach($reader, ['role' => 'reader']);
    $reader->switchTeam($world->tenant);

    $this->actingAs($owner)->get(route('usage.index'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('Usage/Index')
        ->where('usage.calls', 4)
        ->where('engines.0.calls', 4)
        ->where('canViewUsage', true));

    $this->actingAs($reader)->get(route('usage.index'))->assertForbidden();
    $this->actingAs($reader)->get(route('alerts.index'))->assertOk()->assertInertia(fn ($page) => $page
        ->where('canViewUsage', false));
});

test('team settings store a slack webhook and an api key without sending either back', function () {
    Http::preventStrayRequests()->fake([
        'https://api.openai.com/v1/models' => Http::response(['data' => []]),
    ]);
    $world = MetricsWorld::create();
    $world->tenant->forceFill(['byok_enabled' => true, 'monthly_byok_budget_usd' => '15.00'])->save();
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);
    $reader = User::factory()->create();
    $editor = User::factory()->create();
    $world->tenant->users()->attach($reader, ['role' => 'reader']);
    $world->tenant->users()->attach($editor, ['role' => 'editor']);
    $reader->switchTeam($world->tenant);
    $editor->switchTeam($world->tenant);
    $webhook = 'https://hooks.slack.com/services/T000/B000/secret-webhook';

    $this->actingAs($owner)
        ->from(route('teams.show', $world->tenant))
        ->put(route('teams.slack.update', $world->tenant), ['webhook_url' => 'http://hooks.slack.com/services/x'])
        ->assertRedirect()
        ->assertSessionHasErrors('webhook_url');

    $this->actingAs($owner)->put(route('teams.slack.update', $world->tenant), ['webhook_url' => $webhook])->assertRedirect();
    $this->actingAs($owner)->put(route('teams.branding.update', $world->tenant), ['primary' => '#112233'])->assertRedirect();
    $this->actingAs($owner)->put(route('teams.api-keys.store', $world->tenant), [
        'provider' => ApiKeyProvider::OpenAi->value,
        'key' => 'sk-test-secret',
    ])->assertRedirect();

    $team = $world->tenant->fresh();
    $audit = Activity::query()->where('event', 'slack.webhook_changed')->sole();
    expect($team->slack_webhook_url)->toBe($webhook)
        ->and($team->branding['primary'])->toBe('#112233')
        ->and(TenantApiKey::query()->firstOrFail()->key)->toBe('sk-test-secret')
        ->and($audit->toJson())->not->toContain($webhook)
        ->and($audit->toJson())->not->toContain('sk-test-secret');

    $this->actingAs($owner)->get(route('teams.show', $world->tenant))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('teamProfile.slack_configured', true)
            ->where('teamProfile.primary', '#112233')
            ->where('teamProfile.byok_budget', '15.00')
            ->where('teamProfile.keys.0.configured', true))
        ->assertDontSee($webhook)
        ->assertDontSee('sk-test-secret')
        ->assertDontSee('secret-webhook');

    foreach ([$reader, $editor] as $member) {
        $this->actingAs($member)->get(route('teams.show', $world->tenant))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('teamProfile.can_update', false)
                ->missing('teamProfile.keys')
                ->missing('teamProfile.byok_budget'));
    }
});

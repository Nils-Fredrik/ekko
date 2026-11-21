<?php

use App\Api\TokenTenant;
use App\Models\Action;
use App\Models\Brand;
use App\Models\Engine;
use App\Models\EnginePrice;
use App\Models\McpClient;
use App\Models\Plan;
use App\Models\ReportLink;
use App\Models\ReportSchedule;
use App\Models\Team;
use App\Models\UsageEvent;
use App\Models\User;
use App\Reports\DueReport;
use App\Reports\ReportPublisher;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// This entry point is excluded from production images. Never seed an ordinary database.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = DB::connection('pgsql')->getDatabaseName();
$adminDatabase = DB::connection('pgsql_admin')->getDatabaseName();
if (getenv('EKKO_BROWSER_TEST') !== '1' || ! $app->environment('local', 'testing') || ! is_string($database) || ! str_ends_with($database, '_testing') || $database !== env('DB_TEST_DATABASE') || $adminDatabase !== $database) {
    fwrite(STDERR, "Browser fixtures require local/testing and a dedicated _testing database.\n");
    exit(1);
}
if (getenv('EKKO_UI_QUALITY') === '1') {
    Date::setTestNow('2026-10-16 12:00:00 UTC');
    fake()->seed(1500);
}
Artisan::call('migrate:fresh', ['--force' => true]);
$password = bin2hex(random_bytes(24));
$user = User::factory()->create([
    'email' => 'browser-smoke@example.test',
    'password' => Hash::make($password),
    'locale' => 'en',
]);
$team = Team::factory()->create(['name' => 'Browser fixture', 'personal_team' => false]);
$team->users()->attach($user, ['role' => 'reader']);
$user->switchTeam($team);
$brand = app(TenantContext::class)->run($team->id, fn () => Brand::factory()->create(['name' => 'Browser fixture brand']));
// Synthetic credentials for reports published in two languages.
$reportToken = str_repeat('a', 64);
$reportSelector = str_repeat('b', 32);
$norwegianToken = str_repeat('c', 64);
$norwegianSelector = str_repeat('d', 32);
app(TenantContext::class)->run($team->id, function () use ($team, $brand, $reportToken, $reportSelector, $norwegianToken, $norwegianSelector): void {
    Action::factory()->create(['brand_id' => $brand->id, 'title' => 'Published action marker',
        'started_at' => '2026-10-02 12:00:00', 'completed_at' => '2026-10-03 12:00:00']);
    foreach ([['en', $reportToken, $reportSelector], ['nb', $norwegianToken, $norwegianSelector]] as [$locale, $token, $selector]) {
        $schedule = ReportSchedule::factory()->create(['brand_id' => $brand->id, 'locale' => $locale]);
        $due = new DueReport($schedule->id, $brand->id, 'weekly', $locale, ['report@example.test'], '2026-10-01', '2026-10-03', []);
        $publisher = app(ReportPublisher::class);
        // Use the same locale and snapshot path as report publishing, not the test process locale.
        $document = $publisher->document($team, $due, 'Saved report insight');
        $snapshot = $document['data'];
        $snapshot['trend'] = [
            ['period' => '2026-10-01', 'visibility' => ['value' => 0.4, 'n' => 10, 'low_sample' => false]],
            ['period' => '2026-10-03', 'visibility' => ['value' => 0.6, 'n' => 20, 'low_sample' => false]],
        ];
        $report = $publisher->record($due, $document['path'], $snapshot['actions'], $snapshot);
        ReportLink::query()->create(['report_id' => $report->id, 'public_id' => $selector, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay()]);
        // The saved English report must stay English even if the schedule is later changed.
        if ($locale === 'en') {
            $schedule->update(['locale' => 'nb']);
        }
    }
});
$owner = $team->owner;
$owner->forceFill(['email' => 'browser-wizard@example.test', 'password' => Hash::make($password), 'locale' => 'en'])->save();
$owner->switchTeam($team);
$wizardReader = User::factory()->create([
    'email' => 'browser-wizard-reader@example.test', 'password' => Hash::make($password), 'locale' => 'en',
]);
$team->users()->attach($wizardReader, ['role' => 'reader']);
$wizardReader->switchTeam($team);
$engine = Engine::factory()->create(['key' => 'browser_wizard', 'provider' => 'openai', 'is_enabled' => true]);
EnginePrice::factory()->for($engine)->create(['valid_from' => now()->subDay(), 'price_per_call_usd' => '0.01000000']);
$plan = Plan::factory()->create(['allowed_engines' => [$engine->key], 'max_brands' => 5, 'max_markets_per_brand' => 3, 'max_frequency' => 'weekly', 'max_repetitions' => 1, 'monthly_call_quota' => 1000, 'monthly_budget_usd' => '100']);
$team->forceFill(['plan_id' => $plan->id])->save();
$billingAdmin = User::factory()->platformAdmin()->create([
    'email' => 'browser-billing@example.test', 'password' => Hash::make($password), 'locale' => 'en',
]);
$team->users()->attach($billingAdmin, ['role' => 'reader']);
$billingAdmin->switchTeam($team);
// A converted tenant has two invoice rows, but only one tenant-wide markup.
$billingTenant = Team::factory()->tenant()->create(['name' => 'Billing fixture', 'billing_markup_percent' => '10']);
app(TenantContext::class)->run($billingTenant->id, function (): void {
    foreach (['customer', 'pitch'] as $kind) {
        UsageEvent::recordForProject([
            'kind' => 'engine_call', 'occurred_at' => '2026-10-15T12:00:00Z',
            'cost_usd' => '10.00', 'billed_to' => 'platform', 'calls' => 1,
        ], $kind);
    }
});
// T49: a partner advisor who creates a pitch project and follows it to a finished pitch report. The pitch engines
// get prices so the wizard can estimate; collection is replaced by tests/Browser/pitch-collect.php.
foreach (['chatgpt_api' => ['chatgpt', 'openai'], 'gemini_api' => ['gemini', 'google'], 'claude_haiku' => ['claude', 'anthropic']] as $key => [$family, $provider]) {
    $pitchEngine = Engine::query()->firstOrCreate(['key' => $key], ['family' => $family, 'method' => 'api', 'provider' => $provider, 'config' => [], 'is_enabled' => true]);
    $pitchEngine->forceFill(['is_enabled' => true])->save();
    EnginePrice::factory()->for($pitchEngine)->create(['valid_from' => now()->subDay(), 'price_per_call_usd' => '0.01000000']);
}
$partner = Team::factory()->tenant()->create(['name' => 'Browser partner', 'plan_id' => Plan::factory()->create(['name' => 'Browser partner plan'])->id]);
$advisor = User::factory()->withTwoFactor()->create([
    'email' => 'browser-advisor@example.test', 'password' => Hash::make($password), 'locale' => 'en',
]);
$partner->users()->attach($advisor, ['role' => 'partner_advisor']);
$advisor->switchTeam($partner);
$values = ['SMOKE_EMAIL' => $user->email, 'SMOKE_PASSWORD' => $password, 'SMOKE_TENANT_ID' => (string) $team->id, 'SMOKE_BRAND_ID' => (string) $brand->id, 'SMOKE_WIZARD_EMAIL' => $owner->email, 'SMOKE_WIZARD_READER_EMAIL' => $wizardReader->email, 'SMOKE_REPORT_TOKEN' => $reportToken, 'SMOKE_REPORT_SELECTOR' => $reportSelector, 'SMOKE_REPORT_NB_TOKEN' => $norwegianToken, 'SMOKE_REPORT_NB_SELECTOR' => $norwegianSelector, 'SMOKE_ADVISOR_EMAIL' => $advisor->email];
$values = [...$values, ...require __DIR__.'/filter-fixture.php'];
// The visual suite keeps its own fixed data; the portfolio clients would show up on its admin pages.
if (getenv('EKKO_UI_QUALITY') !== '1') {
    $values = [...$values, ...require __DIR__.'/portfolio-fixture.php'];
}
// The integrated T33/T34 contracts are exercised by the deployment smoke test.
if (class_exists(TokenTenant::class)) {
    $values['SMOKE_REST_TOKEN'] = $user->createToken('browser-smoke', ['read', 'tenant:'.$team->id])->plainTextToken;
}
if (class_exists(McpClient::class)) {
    $client = McpClient::query()->create(['id' => 'ekko-browser-smoke', 'name' => 'Browser smoke', 'redirect_uris' => ['http://127.0.0.1:8765/callback']]);
    $values['SMOKE_MCP_CLIENT_ID'] = $client->id;
}
if (getenv('EKKO_UI_QUALITY') === '1') {
    require __DIR__.'/visual/seed.php';
}
echo json_encode($values, JSON_THROW_ON_ERROR);

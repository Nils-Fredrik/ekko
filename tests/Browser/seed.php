<?php

use App\Api\TokenTenant;
use App\Models\Brand;
use App\Models\Engine;
use App\Models\EnginePrice;
use App\Models\McpClient;
use App\Models\Plan;
use App\Models\Report;
use App\Models\ReportLink;
use App\Models\ReportSchedule;
use App\Models\Team;
use App\Models\User;
use App\Reports\ReportPublisher;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
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
$reportToken = str_repeat('a', 64);
$reportSelector = str_repeat('b', 32); // Synthetic fixture only.
app(TenantContext::class)->run($team->id, function () use ($team, $brand, $reportToken, $reportSelector): void {
    $schedule = ReportSchedule::factory()->create(['brand_id' => $brand->id, 'locale' => 'en']);
    $snapshot = app(ReportPublisher::class)->viewData($team, $schedule, '2026-10-01', '2026-10-03', 'Saved report insight');
    $snapshot['trend'] = [
        ['period' => '2026-10-01', 'visibility' => ['value' => 0.4, 'n' => 10, 'low_sample' => false]],
        ['period' => '2026-10-03', 'visibility' => ['value' => 0.6, 'n' => 20, 'low_sample' => false]],
    ];
    $report = Report::factory()->create(['report_schedule_id' => $schedule->id, 'web_snapshot' => $snapshot]);
    ReportLink::query()->create(['report_id' => $report->id, 'public_id' => $reportSelector, 'token_hash' => hash('sha256', $reportToken), 'expires_at' => now()->addDay()]);
});
$owner = $team->owner;
$owner->forceFill(['email' => 'browser-wizard@example.test', 'password' => Hash::make($password), 'locale' => 'en'])->save();
$owner->switchTeam($team);
$engine = Engine::factory()->create(['key' => 'browser_wizard', 'provider' => 'openai', 'is_enabled' => true]);
EnginePrice::factory()->for($engine)->create(['valid_from' => now()->subDay(), 'price_per_call_usd' => '0.01000000']);
$plan = Plan::factory()->create(['allowed_engines' => [$engine->key], 'max_brands' => 5, 'max_markets_per_brand' => 3, 'max_frequency' => 'weekly', 'max_repetitions' => 1, 'monthly_call_quota' => 1000, 'monthly_budget_usd' => '100']);
$team->forceFill(['plan_id' => $plan->id])->save();
$values = ['SMOKE_EMAIL' => $user->email, 'SMOKE_PASSWORD' => $password, 'SMOKE_TENANT_ID' => (string) $team->id, 'SMOKE_BRAND_ID' => (string) $brand->id, 'SMOKE_WIZARD_EMAIL' => $owner->email, 'SMOKE_REPORT_TOKEN' => $reportToken, 'SMOKE_REPORT_SELECTOR' => $reportSelector];
// The integrated T33/T34 contracts are exercised by the deployment smoke test.
if (class_exists(TokenTenant::class)) {
    $values['SMOKE_REST_TOKEN'] = $user->createToken('browser-smoke', ['read', 'tenant:'.$team->id])->plainTextToken;
}
if (class_exists(McpClient::class)) {
    $client = McpClient::query()->create(['id' => 'ekko-browser-smoke', 'name' => 'Browser smoke', 'redirect_uris' => ['http://127.0.0.1:8765/callback']]);
    $values['SMOKE_MCP_CLIENT_ID'] = $client->id;
}
echo json_encode($values, JSON_THROW_ON_ERROR);

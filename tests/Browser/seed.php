<?php

use App\Api\TokenTenant;
use App\Models\Brand;
use App\Models\McpClient;
use App\Models\Team;
use App\Models\User;
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
$values = ['SMOKE_EMAIL' => $user->email, 'SMOKE_PASSWORD' => $password, 'SMOKE_TENANT_ID' => (string) $team->id, 'SMOKE_BRAND_ID' => (string) $brand->id];
// The integrated T33/T34 contracts are exercised by the deployment smoke test.
if (class_exists(TokenTenant::class)) {
    $values['SMOKE_REST_TOKEN'] = $user->createToken('browser-smoke', ['read', 'tenant:'.$team->id])->plainTextToken;
}
if (class_exists(McpClient::class)) {
    $client = McpClient::query()->create(['id' => 'ekko-browser-smoke', 'name' => 'Browser smoke', 'redirect_uris' => ['http://127.0.0.1:8765/callback']]);
    $values['SMOKE_MCP_CLIENT_ID'] = $client->id;
}
echo json_encode($values, JSON_THROW_ON_ERROR);

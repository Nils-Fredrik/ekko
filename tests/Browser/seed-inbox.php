<?php

use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// One waiting quota alert for the inbox browser test. Never run against an ordinary database.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = DB::connection('pgsql')->getDatabaseName();
if (getenv('EKKO_BROWSER_TEST') !== '1' || ! $app->environment('local', 'testing') || ! is_string($database) || ! str_ends_with($database, '_testing') || $database !== env('DB_TEST_DATABASE')) {
    fwrite(STDERR, "Browser fixtures require local/testing and a dedicated _testing database.\n");
    exit(1);
}

$email = 'browser-inbox@example.test';
$user = User::query()->where('email', $email)->first();
if ($user === null) {
    $team = Team::factory()->tenant()->create();
    $user = $team->owner;
    $user->forceFill([
        'email' => $email,
        'password' => Hash::make((string) getenv('SMOKE_PASSWORD')),
        'locale' => 'en',
    ])->save();
    $user->switchTeam($team);
}

app(TenantContext::class)->run((int) $user->current_team_id, function (): void {
    Alert::query()->where('payload->marker', 'browser-inbox')->delete();
    $rule = AlertRule::query()->firstOrCreate(
        ['type' => 'quota', 'brand_id' => null],
        ['is_active' => true, 'threshold' => null, 'channels' => ['in_app']],
    );
    Alert::factory()->create([
        'alert_rule_id' => $rule->id,
        'triggered_at' => now(),
        'acknowledged_at' => null,
        'acknowledged_by' => null,
        'payload' => ['threshold_percent' => 80, 'month' => now()->format('Y-m'), 'marker' => 'browser-inbox'],
    ]);
});

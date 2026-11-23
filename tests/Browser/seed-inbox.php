<?php

use App\Models\Action;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Brand;
use App\Models\BrandFact;
use App\Models\Claim;
use App\Models\Market;
use App\Models\Response;
use App\Models\Run;
use App\Models\RunBatch;
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

$actions = ($argv[1] ?? '') === 'actions';
$email = $actions ? 'browser-inbox-actions@example.test' : 'browser-inbox@example.test';
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

app(TenantContext::class)->run((int) $user->current_team_id, function () use ($actions): void {
    if ($actions) {
        $brand = Brand::firstWhere('name', 'Inbox fixture') ?? Brand::factory()->create(['name' => 'Inbox fixture']);
        if ($brand->markets()->exists()) {
            return;
        }
        $market = Market::factory()->for($brand)->create();
        Action::factory()->for($brand)->create(['market_id' => $market->id, 'title' => 'Review the new source']);
        $run = Run::factory()->create(['run_batch_id' => RunBatch::factory()->for($market)->create()->id, 'status' => 'extracted']);
        $answer = Response::factory()->for($run)->create(['collected_at' => now()->subHour()]);
        $fact = BrandFact::factory()->for($brand)->create(['is_active' => true]);
        Claim::create(['response_id' => $answer->id, 'brand_id' => $brand->id, 'fact_id' => $fact->id,
            'text' => 'Founded in 2015.', 'verdict' => Claim::CONTRADICTED, 'source_citation_ids' => []]);

        return;
    }
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

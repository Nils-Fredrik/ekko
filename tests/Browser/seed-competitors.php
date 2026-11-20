<?php

use App\Models\AggDaily;
use App\Models\Brand;
use App\Models\Competitor;
use App\Models\Engine;
use App\Models\Market;
use App\Models\Mention;
use App\Models\Prompt;
use App\Models\Response;
use App\Models\Run;
use App\Models\RunBatch;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// Synthetic competitors for the U07 browser test (tests/Browser/competitors.spec.mjs). Never run against an ordinary
// database.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = DB::connection('pgsql')->getDatabaseName();
if (getenv('EKKO_BROWSER_TEST') !== '1' || ! $app->environment('local', 'testing') || ! is_string($database) || ! str_ends_with($database, '_testing') || $database !== env('DB_TEST_DATABASE')) {
    fwrite(STDERR, "Browser fixtures require local/testing and a dedicated _testing database.\n");
    exit(1);
}
$tenantId = (int) getenv('SMOKE_TENANT_ID');
// An editor of its own: adding a suggested competitor needs the right to manage competitors, and login is rate
// limited per address.
$editor = User::query()->where('email', 'browser-competitors@example.test')->first();
if ($editor === null) {
    $editor = User::factory()->create(['email' => 'browser-competitors@example.test', 'password' => Hash::make((string) getenv('SMOKE_PASSWORD')), 'locale' => 'en']);
    $team = Team::query()->findOrFail($tenantId);
    $team->users()->attach($editor, ['role' => 'editor']);
    $editor->switchTeam($team);
}
$result = app(TenantContext::class)->run($tenantId, fn (): array => DB::transaction(function (): array {
    $existing = Brand::query()->where('name', 'Browser competitors brand')->first();
    if ($existing !== null) {
        return ['brand' => $existing->id];
    }
    $timezone = Team::query()->findOrFail(current_tenant_id())->timezone ?: 'UTC';
    $today = CarbonImmutable::now($timezone);
    $brand = Brand::factory()->create(['name' => 'Browser competitors brand']);
    $market = Market::factory()->for($brand)->create();
    // Inactive and first by name, so it takes no color position: Alpha rival has the first competitor color.
    Competitor::factory()->for($brand)->create(['name' => 'Aaa retired rival', 'is_active' => false]);
    $alpha = Competitor::factory()->for($brand)->create(['name' => 'Alpha rival']);
    $beta = Competitor::factory()->for($brand)->create(['name' => 'Beta rival']);
    $engine = Engine::query()->where('key', 'browser_competitors')->first() ?? Engine::factory()->create(['key' => 'browser_competitors']);
    $prompt = Prompt::factory()->for($market)->create(['text' => 'Which tea shop do you recommend?']);

    // Alpha leads the period (tied with the brand today), the brand led a week ago.
    foreach ([[0, 20, 12, 12, 4], [1, 20, 10, 12, 3], [8, 10, 6, 2, 1]] as [$daysAgo, $responses, $brandMentioned, $alphaMentions, $betaMentions]) {
        AggDaily::factory()->create([
            'market_id' => $market->id, 'engine_key' => $engine->key, 'prompt_id' => $prompt->id,
            'date' => $today->subDays($daysAgo)->toDateString(), 'responses' => $responses, 'brand_mentioned' => $brandMentioned,
            'competitor_mentions' => [(string) $alpha->id => $alphaMentions, (string) $beta->id => $betaMentions],
            'sentiment_distribution' => [
                'own' => ['negative' => 1, 'neutral' => 3, 'positive' => 6],
                'competitors' => [(string) $alpha->id => ['negative' => 4, 'neutral' => 2, 'positive' => 4]],
            ],
        ]);
    }

    // An unknown brand named in two answers is suggested as a competitor.
    foreach ([1, 2] as $ignored) {
        $batch = RunBatch::factory()->create(['market_id' => $market->id]);
        $run = Run::factory()->create(['run_batch_id' => $batch->id, 'prompt_id' => $prompt->id, 'engine_key' => $engine->key, 'status' => 'extracted']);
        $response = Response::factory()->create(['run_id' => $run->id, 'engine_key' => $engine->key, 'collected_at' => now()->subHour()]);
        Mention::factory()->create(['response_id' => $response->id, 'entity_type' => 'unknown', 'detection' => 'llm', 'surface_name' => 'Gamma tea house']);
    }

    return ['brand' => $brand->id];
}));
echo json_encode([...$result, 'email' => $editor->email], JSON_THROW_ON_ERROR);

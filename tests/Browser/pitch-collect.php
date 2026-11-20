<?php

use App\Models\Action;
use App\Models\Brand;
use App\Models\BrandFact;
use App\Models\Citation;
use App\Models\Claim;
use App\Models\Mention;
use App\Models\Response;
use App\Models\Run;
use App\Models\Team;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Stands in for the collection worker, the extraction and the fact check of the browser pitch (T49): no engine or
// model is called. Every queued run gets an extracted answer, the queued jobs are taken off the queue as a worker
// would, and the first approved fact gets a contradicted claim with a cited page.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = DB::connection()->getDatabaseName();
if (getenv('EKKO_BROWSER_TEST') !== '1' || ! $app->environment('local', 'testing') || ! str_ends_with($database, '_testing') || $database !== env('DB_TEST_DATABASE')) {
    throw new RuntimeException('Browser collection requires the dedicated test database.');
}
$pitch = Team::query()->where('name', getenv('PITCH_NAME') ?: 'Browser Pitch AS')->where('kind', 'pitch')->sole();
$result = app(TenantContext::class)->run($pitch->id, fn () => app(TenantRls::class)->run([$pitch->id], function (): array {
    $brand = Brand::query()->sole();
    $competitors = $brand->competitors()->orderBy('id')->get();
    $runs = Run::query()->where('status', 'queued')->whereHas('prompt.market', fn ($q) => $q->where('brand_id', $brand->id))->orderBy('id')->get();
    $now = now();
    $first = null;
    foreach ($runs as $index => $run) {
        $run->forceFill(['status' => 'extracted'])->save();
        $answer = Response::factory()->create(['run_id' => $run->id, 'engine_key' => $run->engine_key, 'collected_at' => $now]);
        $first ??= $answer;
        // The brand in every other answer, the first competitor in every third.
        if ($index % 2 === 0) {
            Mention::factory()->create(['response_id' => $answer->id, 'entity_type' => 'brand', 'entity_id' => $brand->id, 'detection' => 'deterministic', 'list_position' => 1]);
        }
        if ($competitors->isNotEmpty() && $index % 3 === 0) {
            Mention::factory()->create(['response_id' => $answer->id, 'entity_type' => 'competitor', 'entity_id' => $competitors->first()->id, 'detection' => 'deterministic', 'list_position' => 2]);
        }
        $domain = $index % 2 === 0 ? 'katalog.example.test' : 'nyheter.example.test';
        Citation::factory()->create(['response_id' => $answer->id, 'domain' => $domain, 'url' => "https://{$domain}/side", 'url_normalized' => "https://{$domain}/side", 'ownership' => 'third_party']);
    }
    $fact = BrandFact::query()->where('brand_id', $brand->id)->where('is_active', true)->orderBy('id')->first();
    if ($fact !== null && $first !== null) {
        $citation = Citation::query()->where('response_id', $first->id)->sole();
        Claim::query()->create(['brand_id' => $brand->id, 'response_id' => $first->id, 'fact_id' => $fact->id,
            'text' => 'Grunnlagt i 2005', 'quote' => 'grunnlagt i 2005', 'verdict' => Claim::CONTRADICTED, 'source_citation_ids' => [$citation->id]]);
        $first->forceFill(['facts_checked_at' => $now])->save();
    }
    foreach ([30, 20, 10, 5] as $score) {
        Action::factory()->for($brand)->create(['title' => "Pitch action {$score}", 'impact_score' => $score]);
    }

    return ['runs' => $runs->count()];
}));
// The browser server has no worker: the jobs the wizard queued are taken off as a worker would (the wizard test counts
// the jobs it queues itself).
$result['jobs'] = DB::table('jobs')->delete();
echo json_encode($result, JSON_THROW_ON_ERROR);

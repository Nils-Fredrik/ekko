<?php

use App\Models\Brand;
use App\Models\Run;
use App\Models\Team;
use App\Scheduling\PromptSchedule;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = DB::connection()->getDatabaseName();
if (getenv('EKKO_BROWSER_TEST') !== '1' || ! $app->environment('local', 'testing') || ! str_ends_with($database, '_testing') || $database !== env('DB_TEST_DATABASE')) {
    throw new RuntimeException('Browser verification requires the dedicated test database.');
}
$tenantId = (int) getenv('SMOKE_TENANT_ID');
$result = app(TenantContext::class)->run($tenantId, fn () => app(TenantRls::class)->run([$tenantId], function (): array {
    $brand = Brand::query()->where('name', 'Browser Wizard AS')->sole();
    $market = $brand->markets()->where('country', 'NO')->sole();

    return [
        'due' => $market->prompts()->get()->filter(fn ($prompt) => $prompt->priority === 'core' || PromptSchedule::due($prompt->id, 'weekly', CarbonImmutable::now('UTC')->setTimezone(Team::query()->findOrFail(current_tenant_id())->timezone)->startOfDay()))->count(),
        'markets' => $brand->markets()->count(),
        'swedish_queued' => Run::query()->whereHas('prompt.market', fn ($query) => $query->where('brand_id', $brand->id)->where('country', 'SE'))->whereNotNull('enqueued_at')->count(),
        'core' => $market->prompts()->where('priority', 'core')->count(),
        'queued' => Run::query()->whereIn('prompt_id', $market->prompts()->select('id'))->where('status', 'queued')->whereNotNull('enqueued_at')->count(),
        'jobs' => DB::table('jobs')->count(),
        'competitors' => $brand->competitors()->count(),
    ];
}));
echo json_encode($result, JSON_THROW_ON_ERROR);

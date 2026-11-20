<?php

use App\Models\Market;
use App\Models\Plan;
use App\Models\Prompt;
use App\Prompts\PromptCsv;
use App\Tenancy\Rls\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Metrics\MetricsWorld;

test('a simultaneous pitch import waits for the last prompt slot and cannot overfill it', function () {
    $world = MetricsWorld::create('UTC');
    $pitchPlan = Plan::query()->where('is_pitch_plan', true)->sole();
    $world->tenant->forceFill(['kind' => 'pitch', 'plan_id' => $pitchPlan->id, 'pitch_expires_at' => now()->addDays(7)])->save();
    Prompt::factory()->for($world->market)->count(22)->create();

    config(['database.connections.pitch_concurrent' => config('database.connections.'.config('database.default'))]);
    $other = DB::connection('pitch_concurrent');
    $default = config('database.default');
    DB::setDefaultConnection('pitch_concurrent');
    try {
        $other->beginTransaction();
        $other->statement('SET LOCAL ROLE '.TenantRls::roleName());
        $other->select("SELECT set_config('app.tenant_ids', ?, true)", [(string) $world->tenant->id]);
        Market::query()->whereKey($world->market->id)->lockForUpdate()->firstOrFail();
        Prompt::factory()->for($world->market)->create(['text' => 'Last available prompt?']);
    } finally {
        DB::setDefaultConnection($default);
    }

    $csv = "text,market\nConcurrent import?,NO\n";
    DB::statement("SET lock_timeout = '300ms'");
    try {
        expect(fn () => app(TenantRls::class)->run([$world->tenant->id], fn () => PromptCsv::import($world->brand, $csv)))
            ->toThrow(QueryException::class, 'lock timeout');
    } finally {
        DB::statement('RESET lock_timeout');
    }
    $other->commit();
    DB::disconnect('pitch_concurrent');

    $result = app(TenantRls::class)->run([$world->tenant->id], fn () => PromptCsv::import($world->brand, $csv));
    expect($result['created'])->toBe(0)->and($result['errors'])->not->toBeEmpty();
    expect(Prompt::query()->where('market_id', $world->market->id)->where('is_active', true)->count())->toBe(25);
});

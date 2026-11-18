<?php

use App\Models\AnthropicInsightAllocation;
use App\Models\Engine;
use App\Models\EnginePrice;
use App\Models\ReportSchedule;
use App\Models\UsageEvent;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\FakePdfBuilder;
use Tests\Support\Metrics\MetricsWorld;

test('a known insight settles on the production budget connection', function () {
    expect(DB::transactionLevel())->toBe(0);
    Storage::fake(config()->string('ekko.reports.disk'));
    Mail::fake();
    config(['services.anthropic.key' => 'test-key', 'ekko.anthropic.monthly_pool_usd' => '100']);
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'En tekst.']],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 40],
        ]),
    ]);
    Pdf::swap(new class extends FakePdfBuilder
    {
        public function save(string $path): self
        {
            Storage::disk(config()->string('ekko.reports.disk'))->put($path, '%PDF-fake');

            return $this;
        }
    });
    $this->travelTo('2026-11-17 12:00:00');
    $world = MetricsWorld::create('UTC');
    $engine = Engine::factory()->create(['key' => 'claude_sonnet', 'provider' => 'anthropic', 'is_enabled' => true]);
    EnginePrice::factory()->for($engine)->create([
        'valid_from' => '2026-01-01 00:00:00',
        'price_per_call_usd' => '0.01000000',
        'price_per_1k_input_tokens_usd' => '1.00000000',
        'price_per_1k_output_tokens_usd' => '1.00000000',
        'price_per_search_usd' => '0.00000000',
    ]);
    app(TenantContext::class)->set($world->tenant->id);
    ReportSchedule::factory()->create([
        'brand_id' => $world->brand->id,
        'cadence' => 'monthly',
        'locale' => 'nb',
        'recipients' => ['reports@example.test'],
    ]);
    AnthropicInsightAllocation::query()->create([
        'brand_id' => $world->brand->id,
        'cycle_start' => '2026-11-01',
        'cost_usd' => '0.05000000',
    ]);

    try {
        $this->artisan('ekko:send-reports', ['--tenant' => [$world->tenant->id]])->assertSuccessful();

        $operation = 'insight:'.$world->brand->id.':2026-11-01';
        $event = UsageEvent::query()->where('anthropic_operation', $operation)->sole();
        expect($event->input_tokens)->toBe(100)
            ->and($event->output_tokens)->toBe(40)
            ->and(DB::table('anthropic_credit_spend')->where('entry', $operation)->count())->toBe(1)
            ->and(DB::table('anthropic_budget_holds')->where('operation', 'like', $operation.'%')->whereNull('settled_at')->count())->toBe(0);
    } finally {
        $tables = array_values(array_filter(
            array_column(Schema::getTables(), 'name'),
            fn (string $table): bool => $table !== 'migrations',
        ));
        if ($tables !== []) {
            DB::statement('TRUNCATE '.implode(', ', array_map(fn (string $table): string => '"'.$table.'"', $tables)).' RESTART IDENTITY CASCADE');
        }
        DB::purge('anthropic_budget');
    }
});

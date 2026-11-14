<?php

use App\Models\Activity;
use App\Models\AggDaily;
use App\Models\Alert;
use App\Models\Brand;
use App\Models\BrandDomain;
use App\Models\Engine;
use App\Models\ExtractionJob;
use App\Models\Integration;
use App\Models\Market;
use App\Models\MarketEngine;
use App\Models\Report;
use App\Models\Response;
use App\Models\Team;
use App\Models\UsageMonthly;
use App\Tenancy\MissingTenantContextException;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('seeds a repeatable demo with two clients, a partner and tenant-scoped history', function () {
    $this->seed();
    $this->seed();

    $partner = Team::query()->where('name', 'Demo Partner')->sole();
    $clients = $partner->clients()->orderBy('name')->get();
    $context = app(TenantContext::class);

    expect(Team::query()->count())->toBe(3)
        ->and($clients->pluck('name')->all())->toBe(['Demo Norge', 'Demo Sverige'])
        ->and(Engine::query()->count())->toBe(9);

    foreach ($clients->prepend($partner) as $tenant) {
        $context->run($tenant->id, function () use ($tenant) {
            $brand = Brand::query()->sole();
            $market = $brand->markets()->sole();

            expect($brand->tenant_id)->toBe($tenant->id)
                ->and($market->prompts()->count())->toBe(2)
                ->and($market->marketEngines()->count())->toBe(9)
                ->and(MarketEngine::query()->where('engine_key', 'claude_sonnet')->sole()->prompt_scope)->toBe('core')
                ->and(Response::query()->count())->toBe(14)
                ->and(AggDaily::query()->count())->toBe(14)
                ->and(ExtractionJob::query()->count())->toBe(14)
                ->and((int) UsageMonthly::query()->sum('calls'))->toBe(14)
                ->and(Alert::query()->count())->toBe(1)
                ->and(Report::query()->count())->toBe(1)
                ->and(Activity::query()->sole()->subject->is($brand))->toBeTrue();
        });
    }

    expect($context->check())->toBeFalse();
    expect(fn () => BrandDomain::query()->count())->toThrow(MissingTenantContextException::class);
});

it('rejects a child that references a brand in another tenant', function () {
    $context = app(TenantContext::class);
    $owner = Team::factory()->tenant()->create();
    $other = Team::factory()->tenant()->create();
    $brand = $context->run($owner->id, fn () => Brand::factory()->create());

    $context->run($other->id, function () use ($brand) {
        expect(Market::query()->count())->toBe(0);

        expect(fn () => DB::transaction(fn () => Market::factory()->for($brand)->create()))
            ->toThrow(QueryException::class, 'markets_tenant_id_brand_id_foreign');
    });
});

it('encrypts the integration configuration inside JSONB and omits it from serialization', function () {
    $tenant = Team::factory()->tenant()->create();
    $context = app(TenantContext::class);

    $context->run($tenant->id, function () {
        $config = ['property_id' => '123456789'];
        $integration = Integration::factory()->create(['config' => $config])->refresh();
        $stored = DB::table('integrations')->where('id', $integration->id)->sole();

        expect($integration->config)->toBe($config)
            ->and($integration->toArray())->not->toHaveKey('config')
            ->and(json_decode($stored->config, true, flags: JSON_THROW_ON_ERROR))->toBeString()
            ->and($stored->config)->not->toContain($config['property_id']);
    });
});

<?php

use App\Models\SavedView;
use App\Models\Team;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Metrics\MetricsWorld;

test('saved views are invisible across tenants even in raw SQL and cannot reference another tenants brand', function () {
    $a = MetricsWorld::create();
    $b = MetricsWorld::create();
    $as = fn (Team $tenant, callable $callback) => app(TenantContext::class)->run($tenant->id, fn () => app(TenantRls::class)->run([$tenant->id], $callback));
    $view = $as($b->tenant, fn () => SavedView::query()->create(['user_id' => $b->tenant->owner->id,
        'brand_id' => $b->brand->id, 'page' => 'brands.show', 'name' => 'Private view', 'filters' => ['days' => 7]]));
    $as($a->tenant, function () use ($view) {
        expect(DB::table('saved_views')->where('id', $view->id)->count())->toBe(0)
            ->and(DB::table('saved_views')->where('id', $view->id)->update(['name' => 'Changed']))->toBe(0)
            ->and(DB::table('saved_views')->where('id', $view->id)->delete())->toBe(0);
    });
    expect(fn () => $as($a->tenant, fn () => DB::table('saved_views')->insert([
        'tenant_id' => $a->tenant->id, 'brand_id' => $b->brand->id, 'user_id' => $a->tenant->owner->id,
        'page' => 'brands.show', 'name' => 'Invalid reference', 'filters' => '{}',
    ])))->toThrow(QueryException::class);
    expect(app(TenantRls::class)->run([], fn () => DB::table('saved_views')->count()))->toBe(0);
});

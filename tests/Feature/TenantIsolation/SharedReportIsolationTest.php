<?php

use App\Models\Report;
use App\Models\ReportLink;
use App\Models\ReportLinkView;
use App\Models\ReportSchedule;
use App\Models\Team;
use App\Reports\ReportPublisher;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Metrics\MetricsWorld;

beforeEach(function () {
    $this->a = MetricsWorld::create();
    $this->b = MetricsWorld::create();
    $this->as = fn (Team $tenant, callable $callback) => app(TenantContext::class)->run($tenant->id, fn () => app(TenantRls::class)->run([$tenant->id], $callback));

    $makeLink = function (MetricsWorld $world, string $insight): array {
        return app(TenantContext::class)->run($world->tenant->id, function () use ($world, $insight): array {
            $schedule = ReportSchedule::factory()->create(['brand_id' => $world->brand->id]);
            $snapshot = app(ReportPublisher::class)->viewData($world->tenant, $schedule, '2026-10-01', '2026-10-07', $insight);
            $report = Report::factory()->create(['report_schedule_id' => $schedule->id, 'web_snapshot' => $snapshot]);
            $token = bin2hex(random_bytes(32));
            $link = ReportLink::query()->create([
                'report_id' => $report->id,
                'public_id' => bin2hex(random_bytes(16)),
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDay(),
            ]);

            return ['id' => $link->id, 'report_id' => $report->id, 'selector' => $link->public_id, 'token' => $token];
        });
    };
    $this->linkA = $makeLink($this->a, 'Private tenant A report');
    $this->otherA = $makeLink($this->a, 'Private second report');
    $this->linkB = $makeLink($this->b, 'Private tenant B report');
});

test('shared report bearer credentials cannot cross tenants or reports', function () {
    $endpoint = fn (Team $tenant, array $link) => route('reports.shared', ['tenant' => $tenant->id, 'selector' => $link['selector']]);

    // Both independent credentials work for their own report, so the rejection checks are meaningful.
    $this->withHeader('Authorization', 'Bearer '.$this->linkA['token'])
        ->get($endpoint($this->a->tenant, $this->linkA))->assertOk()->assertSee('Private tenant A report')->assertDontSee('Private tenant B report');
    $this->withHeader('Authorization', 'Bearer '.$this->linkB['token'])
        ->get($endpoint($this->b->tenant, $this->linkB))->assertOk()->assertSee('Private tenant B report')->assertDontSee('Private tenant A report');

    $this->withHeader('Authorization', 'Bearer '.$this->linkA['token'])
        ->get($endpoint($this->b->tenant, $this->linkA))->assertNotFound();
    $this->withHeader('Authorization', 'Bearer '.$this->linkA['token'])
        ->get($endpoint($this->b->tenant, $this->linkB))->assertNotFound();
    $this->withHeader('Authorization', 'Bearer '.$this->linkA['token'])
        ->get($endpoint($this->a->tenant, $this->otherA))->assertNotFound();
    $this->withHeader('Authorization', 'Bearer '.$this->linkB['token'])
        ->get($endpoint($this->a->tenant, $this->linkA))->assertNotFound();

    expect(($this->as)($this->a->tenant, fn () => ReportLink::query()->findOrFail($this->otherA['id'])->views))->toBe(0)
        ->and(($this->as)($this->a->tenant, fn () => ReportLinkView::query()->count()))->toBe(1)
        ->and(($this->as)($this->b->tenant, fn () => ReportLinkView::query()->count()))->toBe(1);
});

test('report link and view rows are isolated by forced RLS even in raw SQL', function () {
    foreach ([[$this->a->tenant, $this->linkA], [$this->b->tenant, $this->linkB]] as [$tenant, $link]) {
        ($this->as)($tenant, fn () => ReportLinkView::query()->create(['report_link_id' => $link['id'], 'viewed_at' => now()]));
    }

    foreach ([[$this->a->tenant, $this->b->tenant, $this->linkB], [$this->b->tenant, $this->a->tenant, $this->linkA]] as [$own, $foreign, $foreignLink]) {
        $seen = ($this->as)($own, fn () => [
            'role' => DB::selectOne('SELECT current_user AS role')->role,
            'foreign_links' => DB::table('report_links')->where('tenant_id', $foreign->id)->count(),
            'foreign_views' => DB::table('report_link_views')->where('tenant_id', $foreign->id)->count(),
            'modified' => DB::table('report_links')->where('id', $foreignLink['id'])->update(['views' => 42]),
            'own_views' => DB::table('report_link_views')->count(),
        ]);
        expect($seen)->toBe(['role' => TenantRls::roleName(), 'foreign_links' => 0, 'foreign_views' => 0, 'modified' => 0, 'own_views' => 1]);
        expect(fn () => ($this->as)($own, fn () => DB::table('report_links')->insert([
            'tenant_id' => $foreign->id, 'report_id' => $foreignLink['report_id'],
            'public_id' => bin2hex(random_bytes(16)), 'token_hash' => hash('sha256', random_bytes(32)),
            'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
        ])))->toThrow(QueryException::class, 'row-level security');
        expect(fn () => ($this->as)($own, fn () => DB::table('report_link_views')->insert([
            'tenant_id' => $foreign->id, 'report_link_id' => $foreignLink['id'], 'viewed_at' => now(),
        ])))->toThrow(QueryException::class, 'row-level security');
    }

    $none = app(TenantRls::class)->run([], fn () => [DB::table('report_links')->count(), DB::table('report_link_views')->count()]);
    expect($none)->toBe([0, 0]);
    $role = DB::selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = ?', [TenantRls::roleName()]);
    expect($role->rolsuper)->toBeFalse()->and($role->rolbypassrls)->toBeFalse();

    foreach (['report_links', 'report_link_views'] as $table) {
        $security = DB::selectOne('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = ?', [$table]);
        expect($security->relrowsecurity)->toBeTrue()->and($security->relforcerowsecurity)->toBeTrue();
    }
});

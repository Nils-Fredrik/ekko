<?php

// T49, docs/spec.md 11.6: the fact page adds, approves, switches off, changes and deletes facts through
// BrandFactManager, and shows the claims with the cited pages of the same answer.

use App\FactCheck\BrandFactManager;
use App\Jobs\CheckTenantFacts;
use App\Models\Brand;
use App\Models\BrandFact;
use App\Models\Citation;
use App\Models\Claim;
use App\Models\Prompt;
use App\Models\Response;
use App\Models\Run;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Feature\TenantIsolation\Support\Scenario;

beforeEach(function () {
    Bus::fake();
    $this->s = Scenario::create();
    $this->a = app(TenantContext::class)->run($this->s->tenantA->id, function () {
        $brand = Brand::factory()->create();
        $fact = BrandFact::factory()->create(['brand_id' => $brand->id, 'text' => 'Grunnlagt i 1998.', 'is_active' => true]);
        $pending = BrandFact::factory()->create(['brand_id' => $brand->id, 'text' => 'Kontor i Bergen.', 'is_active' => false]);
        $prompt = Prompt::factory()->create();
        $run = Run::factory()->create(['prompt_id' => $prompt->id]);
        $response = Response::factory()->create(['run_id' => $run->id]);
        $cited = Citation::factory()->create(['response_id' => $response->id, 'url' => 'https://feil.example.test/om', 'domain' => 'feil.example.test']);
        $elsewhere = Citation::factory()->create(['url' => 'https://annet-svar.example.test/', 'domain' => 'annet-svar.example.test']);
        $contradicted = Claim::query()->create(['brand_id' => $brand->id, 'response_id' => $response->id, 'fact_id' => $fact->id,
            'text' => 'Grunnlagt i 2005', 'quote' => 'grunnlagt i 2005', 'verdict' => Claim::CONTRADICTED,
            'source_citation_ids' => [$cited->id, $elsewhere->id]]);
        Claim::query()->create(['brand_id' => $brand->id, 'response_id' => $response->id, 'fact_id' => $fact->id,
            'text' => 'Startet i 1998', 'quote' => null, 'verdict' => Claim::SUPPORTED, 'source_citation_ids' => []]);

        return compact('brand', 'fact', 'pending', 'prompt', 'cited', 'contradicted');
    });
    $this->admin = $this->s->adminA;
});

it('shows the facts with claim counts and the contradicted claims with sources from the same answer only', function () {
    $this->actingAs($this->admin)->get(route('brands.facts.index', $this->a['brand']))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Brands/Facts')
            ->where('canManage', true)
            ->where('verdict', 'contradicted')
            ->has('facts', 2)
            ->where('facts.0.text', 'Grunnlagt i 1998.')->where('facts.0.is_active', true)
            ->where('facts.0.claims', ['supported' => 1, 'contradicted' => 1, 'unrelated' => 0])
            ->where('facts.1.text', 'Kontor i Bergen.')->where('facts.1.is_active', false)
            ->has('claims', 1)
            ->where('claims.0.fact', 'Grunnlagt i 1998.')
            ->where('claims.0.quote', 'grunnlagt i 2005')
            ->where('claims.0.prompt_id', $this->a['prompt']->id)
            ->has('claims.0.sources', 1)
            ->where('claims.0.sources.0.url', 'https://feil.example.test/om'));

    $this->get(route('brands.facts.index', [$this->a['brand'], 'verdict' => 'supported']))->assertOk()
        ->assertInertia(fn ($page) => $page->where('verdict', 'supported')->has('claims', 1)->where('claims.0.text', 'Startet i 1998'));
    $this->get(route('brands.facts.index', [$this->a['brand'], 'verdict' => 'wrong']))->assertSessionHasErrors('verdict');
});

it('adds, approves, switches off, changes and deletes facts with the manager rules', function () {
    $brand = $this->a['brand'];
    $this->actingAs($this->admin)->post(route('brands.facts.store', $brand), ['text' => '  Har 40 ansatte.  '])->assertRedirect();
    $this->post(route('brands.facts.store', $brand), ['text' => 'har 40 ansatte.'])->assertSessionHasErrors('text');

    $this->patch(route('brands.facts.update', [$brand, $this->a['pending']]), ['is_active' => true])->assertRedirect();
    Bus::assertDispatched(CheckTenantFacts::class);
    $this->patch(route('brands.facts.update', [$brand, $this->a['fact']]), ['is_active' => false])->assertRedirect();
    $this->patch(route('brands.facts.update', [$brand, $this->a['pending']]), ['text' => 'Kontorer i Bergen og Oslo.'])->assertRedirect();

    $state = app(TenantContext::class)->run($this->s->tenantA->id, fn () => [
        'facts' => BrandFact::query()->where('brand_id', $brand->id)->orderBy('id')->get(['text', 'is_active'])->toArray(),
        // Switching the fact off drops the claims judged against it.
        'claims' => Claim::query()->where('fact_id', $this->a['fact']->id)->count(),
    ]);
    expect($state['facts'])->toBe([
        ['text' => 'Grunnlagt i 1998.', 'is_active' => false],
        ['text' => 'Kontorer i Bergen og Oslo.', 'is_active' => true],
        ['text' => 'Har 40 ansatte.', 'is_active' => true],
    ])->and($state['claims'])->toBe(0);

    $this->delete(route('brands.facts.destroy', [$brand, $this->a['fact']]))->assertRedirect();
    expect(app(TenantContext::class)->run($this->s->tenantA->id, fn () => BrandFact::query()->whereKey($this->a['fact']->id)->exists()))->toBeFalse();
});

it('refuses a fact of another brand under this brand, and every change for roles without manage brands', function () {
    $brand = $this->a['brand'];
    $other = app(TenantContext::class)->run($this->s->tenantA->id, fn () => BrandFact::factory()->create([
        'brand_id' => Brand::factory()->create()->id, 'is_active' => true,
    ]));

    $this->actingAs($this->admin)->patch(route('brands.facts.update', [$brand, $other]), ['is_active' => false])->assertNotFound();
    $this->delete(route('brands.facts.destroy', [$brand, $other]))->assertNotFound();
    expect(app(TenantContext::class)->run($this->s->tenantA->id, fn () => $other->fresh()->is_active))->toBeTrue();

    foreach (['editor', 'reader'] as $role) {
        $user = $this->s->userWithRole($role);
        $this->actingAs($user)->get(route('brands.facts.index', $brand))->assertOk()
            ->assertInertia(fn ($page) => $page->where('canManage', false));
        $this->post(route('brands.facts.store', $brand), ['text' => 'Ny tekst.'])->assertForbidden();
        $this->patch(route('brands.facts.update', [$brand, $this->a['fact']]), ['is_active' => false])->assertForbidden();
        $this->delete(route('brands.facts.destroy', [$brand, $this->a['fact']]))->assertForbidden();
    }
});

it('queues the fact check for several facts in one transaction on the PostgreSQL database cache without aborting it', function () {
    // The wizard saves the approved facts in the confirm transaction, and a check may already be queued: the database
    // store's lock would abort the PostgreSQL transaction on the second dispatch.
    config(['cache.default' => 'database']);
    Bus::fake();
    $manager = app(BrandFactManager::class);
    $tenantId = $this->s->tenantA->id;

    app(TenantContext::class)->run($tenantId, fn () => DB::transaction(function () use ($manager): void {
        $manager->add($this->a['brand'], 'Har 40 ansatte.');
        $manager->add($this->a['brand'], 'Selger løpesko.');
    }));

    expect(app(TenantContext::class)->run($tenantId, fn () => BrandFact::query()->where('brand_id', $this->a['brand']->id)->count()))->toBe(4)
        ->and(DB::table('cache_locks')->where('key', 'like', '%CheckTenantFacts:fact-check:'.$tenantId)->count())->toBe(1);
});

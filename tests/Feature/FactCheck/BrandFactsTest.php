<?php

// T48, docs/spec.md 11.6, ADR 0036: facts a brand is checked against: entered through BrandFactManager, suggested
// from the website in the wizard (approved by the user before they are saved), and read by every role of the
// tenant only.

use App\FactCheck\BrandFactManager;
use App\Models\Brand;
use App\Models\BrandFact;
use App\Models\Claim;
use App\Models\Response;
use App\Models\Team;
use App\Models\User;
use App\Onboarding\Model\OnboardingPromptBuilder;
use App\Onboarding\Model\OnboardingSchema;
use App\Onboarding\Model\ProposalParser;
use App\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Metrics\MetricsWorld;
use Tests\Support\Onboarding\OnboardingFakes;

require_once __DIR__.'/../../Support/Onboarding/helpers.php';

// The manager --------------------------------------------------------------------------------------------------

it('adds a fact with cleaned text, and refuses empty, too long and repeated facts and more than the limit', function () {
    $w = MetricsWorld::create();
    $manager = app(BrandFactManager::class);

    $fact = $manager->add($w->brand, "  Fjordkaffe \n ble grunnlagt   i 2009.  ");
    expect($fact->text)->toBe('Fjordkaffe ble grunnlagt i 2009.')->and($fact->is_active)->toBeTrue();

    $error = fn (callable $call): string => rescue(fn () => $call() && '', fn (ValidationException $e) => $e->errors()['text'][0], false);
    expect($error(fn () => $manager->add($w->brand, '   ')))->toBe('Faktaet kan ikke være tomt.')
        ->and($error(fn () => $manager->add($w->brand, str_repeat('a', 301))))->toBe('Faktaet kan ha høyst 300 tegn.')
        // The same fact in other case is a repeat.
        ->and($error(fn () => $manager->add($w->brand, 'FJORDKAFFE ble grunnlagt i 2009.')))->toBe('Dette faktaet er allerede registrert for merkevaren.');

    config(['ekko.facts.max_per_brand' => 2]);
    $manager->add($w->brand, 'Fjordkaffe har brenneri i Bergen.');
    expect($error(fn () => $manager->add($w->brand, 'Fjordkaffe har tre kaffebarer.')))->toBe('En merkevare kan ha høyst 2 fakta.');

    // The same text on another brand is fine.
    $other = Brand::factory()->create();
    expect($manager->add($other, 'Fjordkaffe ble grunnlagt i 2009.')->brand_id)->toBe($other->id);
});

function claimOn(MetricsWorld $w, BrandFact $fact): Claim
{
    $response = $w->answer($w->p1, '2026-11-10 10:00:00', ['brand' => ['pos' => 1]]);

    return Claim::query()->create(['response_id' => $response->id, 'brand_id' => $w->brand->id, 'fact_id' => $fact->id, 'text' => 'Grunnlagt i 2012', 'quote' => 'x', 'verdict' => 'contradicted']);
}

it('drops the claims of a fact when its text changes or it is switched off, but not when nothing changes', function () {
    $w = MetricsWorld::create();
    $manager = app(BrandFactManager::class);
    $fact = $manager->add($w->brand, 'Fjordkaffe ble grunnlagt i 2009.');
    claimOn($w, $fact);
    $kept = claimOn($w, BrandFact::factory()->for($w->brand)->create());

    // Same text, same state: the verdicts still hold.
    $manager->update($fact, 'Fjordkaffe ble grunnlagt i 2009.', true);
    expect(Claim::query()->where('fact_id', $fact->id)->count())->toBe(1);

    $manager->update($fact, 'Fjordkaffe ble grunnlagt i 2010.');
    expect(Claim::query()->where('fact_id', $fact->id)->count())->toBe(0)->and($fact->refresh()->text)->toBe('Fjordkaffe ble grunnlagt i 2010.');

    claimOn($w, $fact);
    $manager->update($fact, active: false);
    expect(Claim::query()->where('fact_id', $fact->id)->count())->toBe(0)->and($fact->refresh()->is_active)->toBeFalse()
        ->and(Claim::query()->whereKey($kept->id)->exists())->toBeTrue();

    // A text that another fact of the brand already has is refused, and the fact is left alone.
    $manager->add($w->brand, 'Fjordkaffe har brenneri i Bergen.');
    expect(fn () => $manager->update($fact, 'fjordkaffe har brenneri i bergen.'))->toThrow(ValidationException::class);
});

it('deleting a fact deletes its claims, and deleting a brand deletes its facts', function () {
    $w = MetricsWorld::create();
    $fact = app(BrandFactManager::class)->add($w->brand, 'Fjordkaffe ble grunnlagt i 2009.');
    claimOn($w, $fact);

    app(BrandFactManager::class)->delete($fact);
    expect(Claim::query()->count())->toBe(0);

    $other = app(BrandFactManager::class)->add($w->brand, 'Fjordkaffe har brenneri i Bergen.');
    claimOn($w, $other);
    Response::query()->delete();
    $w->brand->delete();
    expect(BrandFact::query()->count())->toBe(0)->and(Claim::query()->count())->toBe(0);
});

it('the admin changes facts and every role reads them; a claim cannot be changed by anyone', function () {
    $w = MetricsWorld::create();
    $fact = BrandFact::factory()->for($w->brand)->create();
    $claim = claimOn($w, $fact);
    $roles = ['admin' => [true, true], 'partner_advisor' => [true, true], 'editor' => [true, false], 'reader' => [true, false]];

    foreach ($roles as $role => [$reads, $changes]) {
        $user = User::factory()->create();
        $w->tenant->users()->attach($user, ['role' => $role]);
        expect($user->can('view', $fact))->toBe($reads, $role)
            ->and($user->can('update', $fact))->toBe($changes, $role)
            ->and($user->can('create', BrandFact::class))->toBe($changes, $role)
            ->and($user->can('view', $claim))->toBeTrue($role)
            ->and($user->can('update', $claim))->toBeFalse($role)
            ->and($user->can('delete', $claim))->toBeFalse($role);
    }
});

it('the text of a fact stays out of the revision log', function () {
    $w = MetricsWorld::create();
    $fact = BrandFact::factory()->for($w->brand)->create(['text' => 'Hemmelig tekst fra kunden.']);
    $fact->update(['text' => 'En annen hemmelig tekst.']);

    $entries = Activity::query()->where('subject_type', BrandFact::class)->get();
    expect($entries)->toHaveCount(2)->and(json_encode($entries->toArray()))->not->toContain('hemmelig tekst')->toContain('[changed]');
});

// The wizard ---------------------------------------------------------------------------------------------------

it('proposes facts from the website: capped, cleaned, without personal data and without repeats', function () {
    config(['ekko.wizard.suggestions.max_facts' => 3]);
    $json = OnboardingFakes::brandJson(['facts' => [
        'Eksempel AS ble grunnlagt i 1998.',
        '  Eksempel AS   har butikk i Oslo.  ',
        'eksempel as har butikk i oslo.',
        'Ring oss på 22 33 44 55 eller skriv til post@eksempel.no',
        'kort',
        42,
        'Eksempel AS selger løpesko og tilbehør.',
        'Eksempel AS har kontor i Bergen.',
    ]]);

    $result = (new ProposalParser)->parseBrand($json, 'Eksempel', 'eksempel.no');

    expect($result['proposal']['facts'])->toBe(['Eksempel AS ble grunnlagt i 1998.', 'Eksempel AS har butikk i Oslo.', 'Eksempel AS selger løpesko og tilbehør.'])
        ->and($result['warnings'])->toContain('fact_personal_data')->toContain('fact_dropped');

    // An older reply without the list is still a usable proposal, with no facts.
    $old = json_decode(OnboardingFakes::brandJson(), true);
    unset($old['facts']);
    expect((new ProposalParser)->parseBrand(json_encode($old), 'Eksempel', 'eksempel.no')['proposal']['facts'])->toBe([]);

    expect(OnboardingSchema::brand()['required'])->toContain('facts')
        ->and(OnboardingSchema::brand()['properties']['facts'])->toBe(['type' => 'array', 'items' => ['type' => 'string']]);
});

it('asks the model for facts the pages state, and for nothing from memory', function () {
    $request = (new OnboardingPromptBuilder)->brand('Eksempel', 'eksempel.no', 'NO', 'nb', []);

    expect($request->system)->toContain('- facts: up to 8 short, checkable facts')->toContain('never guess')
        ->and($request->schema['properties'])->toHaveKey('facts');
});

it('saves the facts the user approved with the brand, and nothing before the user confirms', function () {
    [$tenant, $owner] = wizardTenant();
    OnboardingFakes::bind();
    $id = $this->actingAs($owner)->postJson('/onboarding/analyses', startBrandAnalysis())->assertStatus(202)->json('id');
    $proposal = $this->getJson("/onboarding/analyses/{$id}")->json('proposal');
    expect($proposal['facts'])->toBe(['Eksempel AS ble grunnlagt i 1998.', 'Eksempel AS har butikk i Oslo og Bergen.'])
        ->and(inTenant($tenant, fn () => configurationRowCount()))->toBe(0);

    $this->actingAs($owner)->post(route('onboarding.analyses.confirm', $id), [
        'name' => 'Eksempel AS', 'primary_domain' => 'eksempel.no', 'country' => 'no', 'language' => 'nb',
        'topics' => $proposal['topics'], 'prompts' => $proposal['prompts'],
        // The user removed the second suggestion and wrote one of their own.
        'facts' => [$proposal['facts'][0], 'Eksempel AS har 40 ansatte.'],
    ])->assertRedirect();

    inTenant($tenant, function (): void {
        $brand = Brand::query()->where('name', 'Eksempel AS')->firstOrFail();
        expect($brand->facts()->orderBy('id')->pluck('text')->all())->toBe(['Eksempel AS ble grunnlagt i 1998.', 'Eksempel AS har 40 ansatte.'])
            ->and($brand->facts()->where('is_active', true)->count())->toBe(2);
    });
});

it('refuses a repeated or empty fact on the entry, and saves nothing', function () {
    [$tenant, $owner] = wizardTenant();
    OnboardingFakes::bind();
    $id = $this->actingAs($owner)->postJson('/onboarding/analyses', startBrandAnalysis())->json('id');
    $proposal = $this->getJson("/onboarding/analyses/{$id}")->json('proposal');
    $payload = ['name' => 'Eksempel AS', 'primary_domain' => 'eksempel.no', 'country' => 'no', 'language' => 'nb', 'topics' => $proposal['topics'], 'prompts' => $proposal['prompts']];

    $this->actingAs($owner)->from(route('onboarding.wizard'))->post(route('onboarding.analyses.confirm', $id), $payload + ['facts' => ['Samme fakta her.', 'samme fakta her.']])
        ->assertRedirect()->assertSessionHasErrors('facts.1');
    $this->actingAs($owner)->from(route('onboarding.wizard'))->post(route('onboarding.analyses.confirm', $id), $payload + ['facts' => [str_repeat('x', 301)]])
        ->assertSessionHasErrors('facts.0');

    expect(inTenant($tenant, fn () => Brand::query()->count() + BrandFact::query()->count()))->toBe(0);
});

it('a new market on an existing brand adds no facts', function () {
    $tenantId = Team::factory()->tenant()->create()->id;
    app(TenantContext::class)->run($tenantId, function (): void {
        $brand = Brand::factory()->create();
        app(BrandFactManager::class)->add($brand, 'Fjordkaffe ble grunnlagt i 2009.');
        expect(OnboardingSchema::market(['running_shoes'])['properties'])->not->toHaveKey('facts')
            ->and($brand->facts()->count())->toBe(1);
    });
});

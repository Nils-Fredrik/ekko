<?php

use App\ActionEngine\ActionGenerator;
use App\ActionEngine\Sources\FactCorrectionSource;
use App\Alerts\AlertRules;
use App\Alerts\AlertType;
use App\Brands\BrandHealth;
use App\Enums\ActionStatus;
use App\Inbox\InboxQuery;
use App\Jobs\SendTestReport;
use App\Mail\ReportMail;
use App\Models\Action;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Brand;
use App\Models\BrandFact;
use App\Models\Claim;
use App\Models\Integration;
use App\Models\Market;
use App\Models\Prompt;
use App\Models\Report;
use App\Models\ReportSchedule;
use App\Models\RunBatch;
use App\Models\UsageEvent;
use App\Models\User;
use App\Scheduling\CloudRunAccessToken;
use App\Tenancy\TenantContext;
use App\Workspace\WorkspaceCounts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\FakePdfBuilder;
use Tests\Support\Metrics\MetricsWorld;

require_once __DIR__.'/../../Support/MeasurementPlan/helpers.php';

test('the five inbox sources group and count visible rows, then clear as their sources are handled', function () {
    $w = MetricsWorld::create();
    $owner = $w->tenant->owner;
    $owner->switchTeam($w->tenant);
    $this->actingAs($owner);
    $time = now()->subDay()->format('Y-m-d H:i:s');
    $fact = BrandFact::factory()->for($w->brand)->create(['is_active' => true]);
    foreach ([$w->p1, $w->p2] as $prompt) {
        $response = $w->answer($prompt, $time, ['unknown' => 'Havnelys Studio']);
        $claim = Claim::create(['response_id' => $response->id, 'brand_id' => $w->brand->id, 'fact_id' => $fact->id,
            'text' => 'Established in 2015.', 'verdict' => 'contradicted', 'source_citation_ids' => []]);
    }
    $rule = AlertRule::factory()->for($w->brand)->create(['type' => 'visibility_drop']);
    $alerts = Alert::factory()->count(3)->for($rule)->create(['triggered_at' => now(), 'subject' => 'market:123:prompt:'.str_repeat('a', 32),
        'payload' => ['market_id' => $w->market->id, 'engine_key' => 'gemini_api']]);
    Alert::factory()->for($rule)->create(['triggered_at' => now()->subDays(31)]);
    $action = Action::factory()->for($w->brand)->create(['market_id' => $w->market->id]);
    $integration = Integration::factory()->for($w->brand)->create(['type' => 'ga4', 'status' => 'error']);
    $query = app(InboxQuery::class);
    $rows = collect($query->items($w->tenant, $w->brand))->map(fn ($row) => $row->jsonSerialize());
    expect($rows->count())->toBe(5)->and($query->counts($w->tenant, $w->brand))->toBe([
        'all' => 5, 'alert' => 1, 'entity' => 1, 'action' => 1, 'claim' => 1, 'integration' => 1,
    ])->and($rows->firstWhere('type', 'alert')['source_ids'])->toEqualCanonicalizing($alerts->modelKeys())
        ->and($rows->firstWhere('type', 'claim')['source_ids'])->toHaveCount(2);
    expect(WorkspaceCounts::for($w->tenant, $w->brand)['inbox'])->toBe(5);
    foreach ($rows as $row) {
        expect($row['title'].' '.$row['detail'])->not->toMatch('/market:|prompt:|[a-f0-9]{32}|inbox\.[a-z]/');
    }
    $otherMarket = Market::factory()->for($w->brand)->create(['country' => 'SE']);
    expect($query->items($w->tenant, $w->brand, $otherMarket, 'alerts'))->toBeEmpty()
        ->and(WorkspaceCounts::for($w->tenant, $w->brand, $otherMarket)['inbox'])->toBe($query->counts($w->tenant, $w->brand, $otherMarket)['all']);
    $this->post(route('alerts.acknowledge.bulk'), ['ids' => $alerts->modelKeys()])->assertRedirect();
    $this->delete(route('alerts.unacknowledge.bulk'), ['ids' => $alerts->modelKeys()])->assertRedirect();
    expect($query->counts($w->tenant, $w->brand)['alert'])->toBe(1);
    $this->post(route('alerts.acknowledge.bulk'), ['ids' => $alerts->modelKeys()])->assertRedirect();
    $this->postJson(route('brands.entities.decide', $w->brand), ['decision' => 'ignored', 'keys' => ['Havnelys Studio']])->assertSuccessful();
    $foreignBrand = Brand::factory()->create();
    $this->post(route('brands.claims.action', [$foreignBrand, $claim]))->assertNotFound();
    $this->post(route('brands.claims.action', [$w->brand, $claim]))->assertRedirect();
    $this->post(route('brands.claims.action', [$w->brand, $claim]))->assertRedirect();
    $correction = Action::where('type', 'fact_correction')->where('evidence->fact_id', $fact->id)->sole();
    $this->get(route('brands.actions.index', $w->brand))->assertInertia(fn (AssertableInertia $page) => $page->where('flash.action_id', $correction->id));
    expect($correction->prompts()->count())->toBe(2);
    $action->update(['status' => ActionStatus::Planned]);
    $correction->update(['status' => ActionStatus::Planned]);
    $integration->forceFill(['status' => 'synced', 'last_error_at' => now()->subDay()])->save();
    expect($query->counts($w->tenant, $w->brand)['all'])->toBe(0)
        ->and(WorkspaceCounts::for($w->tenant, $w->brand)['inbox'])->toBe(0);
    $history = collect($query->items($w->tenant, $w->brand, handled: true));
    expect($history->pluck('type')->unique()->values()->all())->toEqualCanonicalizing(['alert', 'entity', 'action', 'claim', 'integration'])
        ->and($history->every(fn ($item) => $item->handled))->toBeTrue();
});

test('bulk acknowledgment is atomic across requested ids and undo preserves other users receipts', function () {
    $w = MetricsWorld::create();
    $owner = $w->tenant->owner;
    $owner->switchTeam($w->tenant);
    $this->actingAs($owner);
    $other = User::factory()->create();
    $one = Alert::factory()->create();
    $two = Alert::factory()->create(['acknowledged_at' => now(), 'acknowledged_by' => $other->id]);
    $this->post(route('alerts.acknowledge.bulk'), ['ids' => [$one->id, 99999999]])->assertNotFound();
    expect($one->fresh()->acknowledged_at)->toBeNull();
    $this->post(route('alerts.acknowledge.bulk'), ['ids' => [$one->id, $two->id]])->assertSessionHas('acknowledged_ids', [$one->id]);
    $this->get(route('inbox.index'))->assertInertia(fn (AssertableInertia $page) => $page->where('flash.acknowledged_ids', [$one->id]));
    $this->get(route('alerts.index'))->assertRedirect(route('inbox.index', ['type' => 'alerts']));
    $this->delete(route('alerts.unacknowledge.bulk'), ['ids' => [$one->id, $two->id]])->assertRedirect();
    expect($two->fresh()->acknowledged_by)->toBe($other->id)->and($one->fresh()->acknowledged_at)->toBeNull();
});

test('brand health uses the saved plan, counts each active market and keeps hiding private to its user and brand', function () {
    planCatalogue();
    [$tenant, $user] = planTenant();
    $w = planWorld($tenant, [planRule('chatgpt_api', 'daily')], [planRule('chatgpt_api', 'weekly')]);
    $this->actingAs($user);
    app(TenantContext::class)->set($tenant->id);
    $brand = $w['brand'];
    $empty = Brand::factory()->create();
    $health = app(BrandHealth::class);
    $initial = $health->for($empty);
    expect($initial['markets'])->toBeEmpty()->and($initial['engines_active'])->toBe(0)
        ->and($initial['answers_per_month'])->toBe(0)->and($initial['problems'])->toBeEmpty()
        ->and(collect($initial['checklist'])->where('done', true))->toBeEmpty()
        ->and(collect($initial['checklist'])->where('optional', true)->pluck('key')->all())->toBe(['integration']);
    foreach ([$w['a'], $w['b']] as $scope) {
        Prompt::factory()->for($scope)->count(20)->create(['is_active' => true, 'priority' => 'core']);
    }
    RunBatch::factory()->create(['market_id' => $w['a']->id, 'status' => 'completed']);
    BrandFact::factory()->for($brand)->count(3)->create(['is_active' => true]);
    ReportSchedule::factory()->for($brand)->create(['recipients' => ['report@example.test']]);
    Integration::factory()->for($brand)->create(['type' => 'ga4', 'status' => 'error']);
    Action::factory()->for($brand)->count(2)->create();
    $brand->forceFill(['entities_reviewed_at' => now()])->save();
    $result = $health->for($brand);
    expect(array_column($result['markets'], 'id'))->toBe([$w['a']->id, $w['b']->id])
        ->and($result['markets'][1]['next_due_on'])->not->toBeNull()
        ->and($result['engines_active'])->toBe(1)->and($result['answers_per_month'])->toBeGreaterThan(0)
        ->and($result['markets'][0]['last_completed_at'])->not->toBeNull()->and($result['markets'][0]['next_due_on'])->not->toBeNull()
        ->and(array_column($result['problems'], 'kind'))->toBe(['integration', 'action'])
        ->and(array_column($result['problems'], 'count'))->toBe([1, 2])
        ->and($result['problems'][1]['text'])->toBe(trans_choice('inbox.problems.action', 2))
        ->and($result['problems'][1]['cta'])->toBe(__('inbox.problem_actions.action'))
        ->and($result['problems'][1]['url'])->toBe(route('brands.actions.index', $brand))
        ->and(collect($result['checklist'])->every(fn ($step) => $step['done']))->toBeTrue();
    $this->post(route('brands.checklist.hide', $brand))->assertRedirect();
    expect($health->for($brand)['checklist_hidden'])->toBeTrue()->and($health->for($empty)['checklist_hidden'])->toBeFalse();
    $this->actingAs(User::factory()->create());
    expect($health->for($brand)['checklist_hidden'])->toBeFalse();
});

test('the quota warning on a brand dashboard follows access to the usage page', function () {
    $w = MetricsWorld::create();
    $w->tenant->forceFill(['monthly_call_quota_override' => 1])->save();
    UsageEvent::factory()->create(['calls' => 1]);
    $owner = $w->tenant->owner;
    $owner->switchTeam($w->tenant);
    $reader = User::factory()->create();
    $w->tenant->users()->attach($reader, ['role' => 'reader']);
    $reader->switchTeam($w->tenant);
    $url = route('brands.show', $w->brand);
    $this->actingAs($owner)->get($url)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('health.problems', fn ($items) => collect($items)->contains('url', route('usage.index'))));
    $this->actingAs($reader)->get($url)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('health.problems', fn ($items) => ! collect($items)->contains('kind', 'quota')));
});

test('brand notification settings share legacy data, preserve archive and only send previews to the requesting user', function () {
    Queue::fake();
    $w = MetricsWorld::create();
    $owner = $w->tenant->owner;
    $owner->switchTeam($w->tenant);
    $this->actingAs($owner);
    $other = Brand::factory()->create();
    $url = route('brands.alert-rules.upsert', [$w->brand, 'new_competitor']);
    $this->put($url, ['threshold' => 14, 'channels' => ['email']])->assertRedirect();
    $this->put($url, ['is_active' => false])->assertRedirect();
    $rule = $w->brand->alertRules()->sole();
    expect((float) $rule->threshold)->toBe(14.0)->and($rule->channels)->toBe(['inbox', 'email'])->and($rule->is_active)->toBeFalse();
    $this->put($url, ['threshold' => 25, 'is_active' => true])->assertRedirect();
    expect((float) $rule->fresh()->threshold)->toBe(25.0);
    foreach ([$w->brand, $other] as $brand) {
        $this->put(route('brands.alert-rules.upsert', [$brand, 'integration_error']), ['channels' => []])->assertRedirect();
    }
    expect(AlertRule::whereNull('brand_id')->where('type', 'integration_error')->sole()->channels)->toBe(['inbox']);
    $data = ['cadence' => 'weekly', 'recipients' => ['recipient@example.test'], 'locale' => 'nb', 'branding' => 'tenant'];
    $this->post(route('brands.report-schedules.store', $w->brand), $data)->assertRedirect();
    $schedule = $w->brand->reportSchedules()->sole();
    $this->put(route('brands.report-schedules.update', [$other, $schedule]), $data)->assertNotFound();
    $this->delete(route('brands.report-schedules.destroy', [$other, $schedule]))->assertNotFound();
    $this->put(route('brands.report-schedules.update', [$w->brand, $schedule]), $data)->assertRedirect();
    $previewUrl = route('brands.report-schedules.test', [$w->brand, $schedule]);
    foreach (range(1, 3) as $attempt) {
        $this->post($previewUrl)->assertRedirect();
    }
    $this->post($previewUrl)->assertTooManyRequests();
    Queue::assertPushed(SendTestReport::class, 3);
    $this->travel(61)->minutes();
    $this->post(route('brands.report-schedules.test', [$other, $schedule]))->assertNotFound();
    Queue::assertPushed(SendTestReport::class, 3);
    Mail::fake();
    Storage::fake(config('ekko.reports.disk'));
    Pdf::swap(new class extends FakePdfBuilder
    {
        public function save(string $path): self
        {
            Storage::disk(config('ekko.reports.disk'))->put($path, '%PDF-fake');

            return $this;
        }
    });
    app()->call([new SendTestReport($w->tenant->id, $schedule->id, $owner->id), 'handle']);
    Mail::assertSent(ReportMail::class, fn ($mail) => $mail->hasTo($owner->email) && ! $mail->hasTo('recipient@example.test'));
    $report = Report::factory()->create(['report_schedule_id' => $schedule->id]);
    $this->delete(route('brands.report-schedules.destroy', [$w->brand, $schedule]))->assertRedirect();
    expect($w->brand->reportSchedules()->count())->toBe(0)->and($report->fresh()->reportSchedule->id)->toBe($schedule->id);
});

test('a requested correction survives two weekly evaluations below the automatic threshold', function () {
    config(['ekko.actions.min_answers' => 1, 'ekko.actions.fact_correction.min_responses' => 2]);
    $w = MetricsWorld::create();
    $owner = $w->tenant->owner;
    $owner->switchTeam($w->tenant);
    $this->actingAs($owner);
    $fact = BrandFact::factory()->for($w->brand)->create(['is_active' => true]);
    $makeClaim = function (string $status) use ($w, $fact) {
        $response = $w->answer($w->p1, now()->subDay()->toDateTimeString(), ['status' => $status]);

        return Claim::create(['response_id' => $response->id, 'brand_id' => $w->brand->id, 'fact_id' => $fact->id,
            'text' => 'A synthetic contradiction', 'verdict' => Claim::CONTRADICTED, 'source_citation_ids' => []]);
    };
    $claim = $makeClaim('extracted');
    $unfinished = $makeClaim('collected');
    $this->post(route('brands.claims.action', [$w->brand, $unfinished]))->assertUnprocessable();
    $this->post(route('brands.claims.action', [$w->brand, $claim]))->assertRedirect();
    $action = Action::where('type', 'fact_correction')->sole();
    expect($action->evidence['contradicted_responses'])->toBe(1)->and($action->requested_at)->not->toBeNull();
    $generator = new ActionGenerator([new FactCorrectionSource]);
    foreach ([1, 2] as $weeks) {
        $generator->generate($w->brand, CarbonImmutable::now()->addWeeks($weeks));
    }
    expect($action->fresh()->status)->toBe(ActionStatus::New)->and($action->fresh()->unsupported_generations)->toBe(0);
});

test('the test report wakes the dispatcher after its job is visible on the database queue', function () {
    $w = MetricsWorld::create();
    $owner = $w->tenant->owner;
    $owner->switchTeam($w->tenant);
    $this->actingAs($owner);
    $schedule = ReportSchedule::factory()->for($w->brand)->create();
    config(['queue.default' => 'database', 'collection.cloud_run.enabled' => true,
        'collection.cloud_run.project' => 'ekko-fixture', 'collection.cloud_run.region' => 'europe-west1']);
    $this->mock(CloudRunAccessToken::class)->shouldReceive('get')->andReturn('fixture-token');
    $queued = null;
    Http::fake([
        'run.googleapis.com/*/executions*' => function () use (&$queued) {
            $queued = DB::table('jobs')->where('payload', 'like', '%SendTestReport%')->count();

            return Http::response(['executions' => []]);
        },
        'run.googleapis.com/*:run' => Http::response(['name' => 'operation']),
    ]);
    $this->post(route('brands.report-schedules.test', [$w->brand, $schedule]))->assertRedirect();
    expect($queued)->toBe(1);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/jobs/ekko-wake:run') && $request->body() === '{}');
});

test('creating an integration rule preserves legacy opt-outs until a workspace edit changes that field', function () {
    $w = MetricsWorld::create();
    $owner = $w->tenant->owner;
    $owner->switchTeam($w->tenant);
    $this->actingAs($owner);
    $other = Brand::factory()->create();
    $type = AlertType::IntegrationError;
    $legacy = AlertRule::factory()->for($w->brand)->create(['type' => $type->value, 'is_active' => false, 'channels' => ['in_app']]);
    $rules = app(AlertRules::class);
    $effective = fn () => $rules->forSource($type, $w->brand->id);
    $global = $rules->forSource($type, $other->id);
    expect($global->brand_id)->toBeNull()->and($global->is_active)->toBeTrue()
        ->and($effective()->id)->toBe($legacy->id)->and($effective()->is_active)->toBeFalse();

    $url = route('brands.alert-rules.upsert', [$other, $type->value]);
    $this->put($url, ['channels' => ['email']])->assertRedirect();
    expect($effective()->is_active)->toBeFalse()->and($effective()->channels)->toBe(['inbox', 'email'])
        ->and($global->fresh()->channels)->toBe(['inbox', 'email']);
    $this->put($url, ['is_active' => true])->assertRedirect();
    expect($effective()->is_active)->toBeTrue();
    $this->put($url, ['is_active' => false])->assertRedirect();
    expect($effective()->is_active)->toBeFalse()->and($global->fresh()->is_active)->toBeFalse();
});

test('the workspace creates and updates one tenant-wide integration failure rule', function () {
    $w = MetricsWorld::create();
    $owner = $w->tenant->owner;
    $owner->switchTeam($w->tenant);
    $this->actingAs($owner);
    $this->get(route('alerts.index'))->assertRedirect(route('inbox.index', ['type' => 'alerts']));
    $this->get(route('brands.setup.notifications', $w->brand))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Brands/Setup/Notifications')
        ->where('rules.4.type', 'model_change')->where('rules.4.tenant_wide', true)
        ->where('rules.5.type', 'quota')->where('rules.5.tenant_wide', true)
        ->where('rules.6.type', 'integration_error')->where('rules.6.tenant_wide', true));
    $url = route('brands.alert-rules.upsert', [$w->brand, 'integration_error']);
    $this->put($url, ['is_active' => false])->assertSessionHasNoErrors();
    $rule = AlertRule::where('type', 'integration_error')->sole();
    expect($rule->brand_id)->toBeNull()->and($rule->is_active)->toBeFalse();
    $this->put($url, ['is_active' => true])->assertSessionHasNoErrors();
    expect($rule->fresh()->is_active)->toBeTrue();

    $this->post('/alerts/rules', [])->assertNotFound();
    $this->put('/alerts/rules/'.$rule->id, [])->assertNotFound();
    $this->post('/reports/schedules', [])->assertStatus(405);
    $this->put('/reports/schedules/1', [])->assertNotFound();
});

test('an unknown name counts once across qualifying markets and keeps its measured basis', function () {
    $w = MetricsWorld::create();
    app()->setLocale('nb');
    $second = Market::factory()->for($w->brand)->create(['country' => 'SE', 'language' => 'sv']);
    $third = Market::factory()->for($w->brand)->create(['country' => 'DK', 'language' => 'da']);
    $swedish = Prompt::factory()->for($second)->create();
    $danish = Prompt::factory()->for($third)->create();
    $now = now()->subHour()->toDateTimeString();
    AlertRule::factory()->for($w->brand)->create(['type' => 'new_competitor', 'threshold' => 50, 'is_active' => true]);
    foreach ([$w->p1, $swedish, $danish, $w->p1, $swedish, $danish] as $prompt) {
        $w->answer($prompt, $now, ['unknown' => 'Havnelys Studio']);
    }
    $w->answer($danish, $now);
    $w->answer($danish, $now);
    $query = app(InboxQuery::class);
    $rows = $query->items($w->tenant, $w->brand, type: 'entities');
    expect($rows)->toHaveCount(1);
    $row = $rows[0]->jsonSerialize();
    expect($row['market_labels'])->toEqualCanonicalizing(['Norge', 'Sverige'])
        ->and($row['answers'])->toBe(4)->and($row['engine_count'])->toBe(1)
        ->and($row['brand']['name'])->toBe($w->brand->name)->and($row['title'])->toContain('Havnelys Studio')
        ->and($row['at'])->toBe(CarbonImmutable::parse($now)->utc()->toIso8601String())
        ->and($query->counts($w->tenant, $w->brand)['entity'])->toBe(1)
        ->and(WorkspaceCounts::for($w->tenant, $w->brand)['inbox'])->toBe(1)
        ->and($query->items($w->tenant, $w->brand, $third, 'entities'))->toBeEmpty();
    $scoped = $query->items($w->tenant, $w->brand, $second, 'entities')[0]->jsonSerialize();
    expect($scoped['id'])->toBe($row['id'])->and($scoped['market_labels'])->toBe(['Sverige']);
});

test('only the originating user can undo an unchanged inbox status transition once', function () {
    $w = MetricsWorld::create();
    $owner = $w->tenant->owner;
    $owner->switchTeam($w->tenant);
    $editor = User::factory()->create();
    $w->tenant->users()->attach($editor, ['role' => 'editor']);
    $editor->switchTeam($w->tenant);
    $action = Action::factory()->for($w->brand)->create(['unsupported_generations' => 1]);
    $status = route('brands.actions.update', [$w->brand, $action]);
    $undo = route('brands.inbox.undo-action', [$w->brand, $action]);
    $apply = fn () => ['undo_token' => $this->actingAs($owner)->patchJson($status, ['status' => 'planned', 'inbox_undo' => true])->assertOk()->json('undo_token')];
    $receipt = $apply();
    $this->actingAs($editor)->deleteJson($undo, $receipt)->assertConflict();
    expect($action->fresh()->status)->toBe(ActionStatus::Planned);
    $this->actingAs($owner)->deleteJson($undo, $receipt)->assertNoContent();
    expect($action->fresh()->status)->toBe(ActionStatus::New)->and($action->fresh()->unsupported_generations)->toBe(1);
    $this->deleteJson($undo, $receipt)->assertConflict();

    $receipt = $apply();
    $action->refresh()->update(['description' => 'Updated after planning']);
    $this->deleteJson($undo, $receipt)->assertConflict();
    expect($action->fresh()->status)->toBe(ActionStatus::Planned);
});

test('undoing a correction restores a revived action and its previous prompt associations', function () {
    $w = MetricsWorld::create();
    $owner = $w->tenant->owner;
    $owner->switchTeam($w->tenant);
    $this->actingAs($owner);
    $fact = BrandFact::factory()->for($w->brand)->create(['is_active' => true]);
    $response = $w->answer($w->p1, now()->subHour()->toDateTimeString());
    $claim = Claim::create(['response_id' => $response->id, 'brand_id' => $w->brand->id, 'fact_id' => $fact->id,
        'text' => 'Synthetic contradiction', 'verdict' => Claim::CONTRADICTED, 'source_citation_ids' => []]);
    $url = route('brands.claims.action', [$w->brand, $claim]);
    $this->post($url)->assertRedirect();
    $action = Action::where('type', 'fact_correction')->sole();
    $action->forceFill(['status' => ActionStatus::Expired, 'requested_at' => null, 'title' => 'Earlier title', 'unsupported_generations' => 2])->save();
    $action->prompts()->syncWithPivotValues([$w->p2->id], ['tenant_id' => $w->tenant->id]);
    $receipt = $this->postJson($url, ['inbox_undo' => true])->assertOk()->json('undo_token');
    expect($action->fresh()->status)->toBe(ActionStatus::New)->and($action->prompts()->pluck('prompts.id')->all())->toBe([$w->p1->id]);
    $this->postJson($url, ['inbox_undo' => true])->assertOk()->assertJsonPath('undo_token', null);
    $this->deleteJson(route('brands.inbox.undo-action', [$w->brand, $action]), ['undo_token' => $receipt])->assertNoContent();
    $action->refresh();
    expect($action->title)->toBe('Earlier title')->and($action->status)->toBe(ActionStatus::Expired)
        ->and($action->requested_at)->toBeNull()->and($action->unsupported_generations)->toBe(2)
        ->and($action->prompts()->pluck('prompts.id')->all())->toBe([$w->p2->id]);
});

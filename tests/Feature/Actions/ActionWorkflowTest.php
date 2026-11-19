<?php

// T43, docs/spec.md 11.3: the status flow, the dates of the action markers (T45) and the user's own actions.

use App\ActionEngine\ActionWorkflow;
use App\ActionEngine\InvalidActionTransition;
use App\Enums\ActionStatus;
use App\Models\Action;
use App\Models\Brand;
use App\Models\Prompt;
use App\Tenancy\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Metrics\MetricsWorld;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 08:00:00', 'UTC'));
    $this->w = MetricsWorld::create();
    $this->flow = new ActionWorkflow;
    $this->action = Action::factory()->create(['brand_id' => $this->w->brand->id, 'market_id' => $this->w->market->id]);
});

test('in_progress stores the start date once and done stores the completion date', function () {
    $this->flow->transition($this->action, ActionStatus::InProgress);
    expect($this->action->started_at->toDateTimeString())->toBe('2026-11-16 08:00:00');

    $this->travel(2)->days();
    $this->flow->transition($this->action, ActionStatus::Planned);
    $this->flow->transition($this->action, ActionStatus::InProgress);
    expect($this->action->started_at->toDateTimeString())->toBe('2026-11-16 08:00:00');

    $this->travel(3)->days();
    $this->flow->transition($this->action, ActionStatus::Done);
    $fresh = $this->action->fresh();
    expect($fresh->status)->toBe(ActionStatus::Done)
        ->and($fresh->started_at->toDateTimeString())->toBe('2026-11-16 08:00:00')
        ->and($fresh->completed_at->toDateTimeString())->toBe('2026-11-21 08:00:00');

    // Reopened: no longer completed.
    $this->flow->transition($this->action, ActionStatus::InProgress);
    expect($this->action->fresh()->completed_at)->toBeNull()
        ->and($this->action->fresh()->started_at->toDateTimeString())->toBe('2026-11-16 08:00:00');
});

test('done straight from new has a completion date and no start date', function () {
    $this->flow->transition($this->action, ActionStatus::Done);

    expect($this->action->fresh()->completed_at)->not->toBeNull()->and($this->action->fresh()->started_at)->toBeNull();
});

test('a user cannot set new or expired, nor move a declined action other than back to planned', function (ActionStatus $from, ActionStatus $to) {
    $this->action->forceFill(['status' => $from])->save();

    expect(fn () => $this->flow->transition($this->action, $to))->toThrow(InvalidActionTransition::class);
})->with([
    'new to new' => [ActionStatus::New, ActionStatus::New],
    'new to expired' => [ActionStatus::New, ActionStatus::Expired],
    'planned to new' => [ActionStatus::Planned, ActionStatus::New],
    'declined to done' => [ActionStatus::Declined, ActionStatus::Done],
    'expired to in progress' => [ActionStatus::Expired, ActionStatus::InProgress],
    'done to declined' => [ActionStatus::Done, ActionStatus::Declined],
]);

test('an expired or declined action can be planned again', function (ActionStatus $from) {
    $this->action->forceFill(['status' => $from, 'unsupported_generations' => 2])->save();

    $this->flow->transition($this->action, ActionStatus::Planned);

    expect($this->action->fresh()->status)->toBe(ActionStatus::Planned)->and($this->action->fresh()->unsupported_generations)->toBe(0);
})->with([ActionStatus::Expired, ActionStatus::Declined]);

test('steps can be ticked off', function () {
    $this->action->forceFill(['steps' => [['text' => 'a', 'done' => false], ['text' => 'b', 'done' => false]]])->save();

    $this->flow->setStepDone($this->action, 1, true);

    expect($this->action->fresh()->steps)->toEqual([['text' => 'a', 'done' => false], ['text' => 'b', 'done' => true]])
        ->and(fn () => $this->flow->setStepDone($this->action, 5, true))->toThrow(InvalidArgumentException::class);
});

test('a user creates an own action as planned, without fingerprint and with its prompts', function () {
    $own = $this->flow->createOwn($this->w->brand, 'Skriv en guide', 'Om kaffe', ['Skriv', 'Publiser'], marketId: $this->w->market->id, promptIds: [$this->w->p1->id]);

    expect($own->status)->toBe(ActionStatus::Planned)
        ->and($own->created_by)->toBe('user')
        ->and($own->fingerprint)->toBeNull()
        ->and($own->type)->toBeNull()
        ->and($own->text_source)->toBe('user')
        ->and($own->steps)->toEqual([['text' => 'Skriv', 'done' => false], ['text' => 'Publiser', 'done' => false]])
        ->and($own->prompts->pluck('id')->all())->toBe([$this->w->p1->id]);
});

test('an own action refuses a market or prompt of another brand', function () {
    $other = Brand::factory()->create();
    $foreignPrompt = Prompt::factory()->create();

    expect(fn () => $this->flow->createOwn($this->w->brand, 'x', marketId: $foreignPrompt->market_id))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->flow->createOwn($this->w->brand, 'x', promptIds: [$foreignPrompt->id]))->toThrow(InvalidArgumentException::class)
        ->and(Action::query()->where('brand_id', $other->id)->count())->toBe(0);
});

test('status changes are written to the revision log without the title', function () {
    $this->flow->transition($this->action, ActionStatus::InProgress);

    $activity = Activity::query()->where('log_name', AuditLog::LOG_NAME)->where('subject_type', Action::class)->where('event', 'updated')->latest('id')->first();
    expect($activity)->not->toBeNull()
        ->and(json_encode($activity->attribute_changes))->toContain('in_progress')
        ->and(json_encode($activity->attribute_changes))->not->toContain($this->action->title);
});

test('the database refuses a system action without fingerprint', function () {
    expect(fn () => Action::factory()->create(['brand_id' => $this->w->brand->id, 'fingerprint' => null]))->toThrow(QueryException::class);
});

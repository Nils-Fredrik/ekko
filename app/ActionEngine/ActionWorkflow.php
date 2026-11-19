<?php

namespace App\ActionEngine;

use App\Enums\ActionStatus;
use App\Enums\ActionType;
use App\Models\Action;
use App\Models\ActionPrompt;
use App\Models\Brand;
use App\Models\Market;
use App\Models\Prompt;
use App\Models\Topic;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The user's side of actions (docs/spec.md 11.3, ADR 0034), for the interface (T44), the API and MCP (T50):
 * status changes, ticking off steps and the user's own actions. Runs in the tenant's context; authorisation is
 * the caller's. Changes are written to the revision log by the model (RecordsRevisions).
 *
 * Action markers (T45): started_at is set the first time the action moves to in_progress and kept after that;
 * completed_at is set when it moves to done and cleared when it is reopened.
 */
final class ActionWorkflow
{
    public function transition(Action $action, ActionStatus $to, ?CarbonImmutable $now = null): Action
    {
        if (! $action->status->userMayMoveTo($to)) {
            throw InvalidActionTransition::between($action->status, $to);
        }
        $now = ($now ?? CarbonImmutable::now())->utc();

        if ($to === ActionStatus::InProgress && $action->started_at === null) {
            $action->started_at = $now;
        }
        if ($to === ActionStatus::Done) {
            $action->completed_at = $now;
        } elseif ($action->status === ActionStatus::Done) {
            $action->completed_at = null;
        }
        $action->status = $to;
        // A revived action starts over; the next generation counts its support again.
        $action->unsupported_generations = 0;
        $action->save();

        return $action;
    }

    public function setStepDone(Action $action, int $index, bool $done): Action
    {
        $steps = $action->steps;
        if (! isset($steps[$index])) {
            throw new InvalidArgumentException("No step {$index}.");
        }
        $steps[$index]['done'] = $done;
        $action->steps = $steps;
        $action->save();

        return $action;
    }

    /**
     * A user's own action (status planned, no fingerprint, never expired by the generation).
     *
     * @param  list<string>  $steps
     * @param  list<int>  $promptIds  prompts of the brand
     */
    public function createOwn(
        Brand $brand,
        string $title,
        string $description = '',
        array $steps = [],
        ?ActionType $type = null,
        ?int $marketId = null,
        ?int $topicId = null,
        array $promptIds = [],
        ?string $locale = null,
    ): Action {
        if ($marketId !== null && ! Market::query()->whereKey($marketId)->where('brand_id', $brand->id)->exists()) {
            throw new InvalidArgumentException('The market does not belong to the brand.');
        }
        if ($topicId !== null && ! Topic::query()->whereKey($topicId)->where('brand_id', $brand->id)->exists()) {
            throw new InvalidArgumentException('The topic does not belong to the brand.');
        }
        $promptIds = array_values(array_unique(array_map('intval', $promptIds)));
        $owned = Prompt::query()->whereIn('id', $promptIds)
            ->whereHas('market', fn ($q) => $q->where('brand_id', $brand->id))->pluck('id')->all();
        if (count($owned) !== count($promptIds)) {
            throw new InvalidArgumentException('A prompt does not belong to the brand.');
        }

        $action = Action::query()->create([
            'brand_id' => $brand->id,
            'market_id' => $marketId,
            'type' => $type,
            'topic_id' => $topicId,
            'title' => $title,
            'description' => $description,
            'steps' => array_map(fn (string $text): array => ['text' => $text, 'done' => false], $steps),
            'status' => ActionStatus::Planned,
            'created_by' => Action::CREATED_BY_USER,
            'text_source' => 'user',
            'locale' => $locale ?? app()->getLocale(),
        ]);
        if ($promptIds !== []) {
            ActionPrompt::query()->insert(array_map(fn (int $id): array => ['action_id' => $action->id, 'prompt_id' => $id, 'tenant_id' => $action->tenant_id], $promptIds));
        }

        return $action;
    }
}

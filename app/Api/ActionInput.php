<?php

namespace App\Api;

use App\ActionEngine\ActionWorkflow;
use App\ActionEngine\InvalidActionTransition;
use App\Enums\ActionStatus;
use App\Enums\ActionType;
use App\Models\Action;
use App\Models\Brand;
use App\Models\Market;
use App\Models\Prompt;
use App\Models\Topic;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Shared write contract for REST and MCP; callers supply an already isolated brand. */
final class ActionInput
{
    /** @param array<string, mixed> $input */
    public function create(Brand $brand, array $input): Action
    {
        Gate::authorize('view', $brand);
        Gate::authorize('create', Action::class);
        $data = Validator::make($input, [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'string', 'max:5000'],
            'steps' => ['sometimes', 'array', 'max:25'],
            'steps.*' => ['required', 'string', 'max:1000'],
            'type' => ['nullable', Rule::enum(ActionType::class)],
            'market_id' => ['nullable', 'integer', 'min:1'],
            'topic_id' => ['nullable', 'integer', 'min:1'],
            'prompt_ids' => ['sometimes', 'array', 'max:100'],
            'prompt_ids.*' => ['integer', 'min:1', 'distinct'],
            'tenant_id' => ['prohibited'],
        ])->validate();
        if (isset($data['market_id'])) {
            Market::query()->where('brand_id', $brand->id)->findOrFail($data['market_id']);
        }
        if (isset($data['topic_id'])) {
            Topic::query()->where('brand_id', $brand->id)->findOrFail($data['topic_id']);
        }
        foreach ($data['prompt_ids'] ?? [] as $id) {
            Prompt::query()->whereHas('market', fn ($q) => $q->where('brand_id', $brand->id))->findOrFail($id);
        }

        return app(ActionWorkflow::class)->createOwn($brand, $data['title'], $data['description'] ?? '', $data['steps'] ?? [],
            isset($data['type']) ? ActionType::from($data['type']) : null,
            $data['market_id'] ?? null, $data['topic_id'] ?? null, $data['prompt_ids'] ?? []);
    }

    /** @param array<string, mixed> $input */
    public function update(Brand $brand, array $input): Action
    {
        Gate::authorize('view', $brand);
        Gate::authorize('create', Action::class);
        $data = Validator::make($input, [
            'id' => ['required', 'integer', 'min:1'],
            'status' => ['required', Rule::enum(ActionStatus::class)],
            'tenant_id' => ['prohibited'],
        ])->validate();
        $action = Action::query()->where('brand_id', $brand->id)->lockForUpdate()->findOrFail($data['id']);
        Gate::authorize('update', $action);
        $target = ActionStatus::from($data['status']);
        if ($action->status === $target) {
            return $action;
        }
        try {
            return app(ActionWorkflow::class)->transition($action, $target);
        } catch (InvalidActionTransition) {
            throw ValidationException::withMessages(['status' => __('action_items.invalid_status')]);
        }
    }
}

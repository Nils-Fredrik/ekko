<?php

namespace App\Prompts;

use App\Models\Brand;
use App\Models\Prompt;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class PromptBulk
{
    public const ACTIONS = ['move_topic', 'set_priority', 'set_intent', 'set_funnel', 'activate', 'deactivate', 'archive', 'unarchive', 'create', 'undo'];

    public function __construct(private PromptLibrary $library) {}

    /**
     * @param  list<int>  $ids
     */
    public function apply(Brand $brand, array $ids, string $action, mixed $value): BulkResult
    {
        if ($action === 'create') {
            $market = $brand->markets()->findOrFail($value['market_id']);

            return $this->library->withinLimits([$market->id], function () use ($brand, $market, $value) {
                $groups = [];
                foreach ($value['prompts'] as $data) {
                    $prompt = $this->library->saveLocked($market, null, $data);
                    $this->remember($groups, $prompt->id, null, $this->snapshot($prompt, ['text', 'is_active', 'archived_at', 'topic_id', 'priority', 'intent', 'funnel_stage']));
                }

                return $this->result($brand, $groups);
            });
        }

        $selection = Prompt::query()->whereHas('market', fn ($q) => $q->where('brand_id', $brand->id))->whereKey($ids);
        $markets = (clone $selection)->distinct()->pluck('market_id')->all();

        return $this->library->withinLimits($markets, function () use ($brand, $ids, $action, $value, $selection) {
            $prompts = $selection->with('market')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($prompts->count() === count($ids), 404);
            if ($action === 'undo') {
                $undo = $this->openUndo($brand, $prompts->keys()->all(), $value);
                foreach ($undo['groups'] as $group) {
                    foreach ($group['ids'] as $id) {
                        $prompt = $prompts->get($id);
                        if ($this->snapshot($prompt, array_keys($group['after'])) !== $group['after']) {
                            throw ValidationException::withMessages(['ids' => __('app.library.undo_conflict')]);
                        }
                        if ($group['before'] === null) {
                            $this->library->delete($prompt);
                        } else {
                            $this->library->saveLocked($prompt->market()->firstOrFail(), $prompt, $group['before']);
                        }
                    }
                }

                return new BulkResult(count($ids), null);
            }
            $groups = [];
            foreach ($prompts as $prompt) {
                $changes = $this->changes($action, $value, $prompt);
                $before = $this->snapshot($prompt, array_keys($changes));
                $saved = $this->library->saveLocked($prompt->market()->firstOrFail(), $prompt, $changes);
                $after = $this->snapshot($saved, array_keys($changes));
                if ($before !== $after) {
                    $this->remember($groups, $prompt->id, $before, $after);
                }
            }

            return $this->result($brand, $groups);
        });
    }

    /**
     * @param  list<int>  $ids
     * @return array<string, mixed>
     */
    private function openUndo(Brand $brand, array $ids, string $token): array
    {
        try {
            $undo = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            $undo = null;
        }
        if (! is_array($undo) || ($undo['type'] ?? null) !== 'prompt-bulk-undo'
            || ($undo['brand'] ?? null) !== $brand->id || ($undo['tenant'] ?? null) !== current_tenant_id()) {
            throw ValidationException::withMessages(['value' => __('app.library.undo_conflict')]);
        }
        $boundIds = array_merge([], ...array_column($undo['groups'], 'ids'));
        sort($boundIds);
        if ($boundIds !== $ids) {
            throw ValidationException::withMessages(['ids' => __('app.library.undo_conflict')]);
        }

        return $undo;
    }

    /** @return array<string, mixed> */
    private function changes(string $action, mixed $value, Prompt $prompt): array
    {
        $field = match ($action) {
            'move_topic' => 'topic_id', 'set_priority' => 'priority', 'set_intent' => 'intent',
            'set_funnel' => 'funnel_stage', default => null,
        };
        if ($field !== null) {
            return Validator::make([$field => $value], [$field => PromptLibrary::rules(false)[$field]])->validate();
        }

        return match ($action) {
            'activate' => ['is_active' => true],
            'deactivate' => ['is_active' => false],
            'archive' => ['archived_at' => $prompt->archived_at ?? now(), 'is_active' => false],
            'unarchive' => $prompt->archived_at === null ? [] : ['archived_at' => null, 'is_active' => false],
            default => throw ValidationException::withMessages(['action' => __('validation.in', ['attribute' => 'action'])]),
        };
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function snapshot(Prompt $prompt, array $keys): array
    {
        $values = $prompt->only($keys);
        if (isset($values['archived_at'])) {
            $values['archived_at'] = $prompt->archived_at?->format('Y-m-d H:i:sP');
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $groups
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    private function remember(array &$groups, int $id, ?array $before, array $after): void
    {
        $key = json_encode([$before, $after], JSON_THROW_ON_ERROR);
        $groups[$key] ??= ['before' => $before, 'after' => $after, 'ids' => []];
        $groups[$key]['ids'][] = $id;
    }

    /**
     * @param  array<string, mixed>  $groups
     */
    private function result(Brand $brand, array $groups): BulkResult
    {
        $ids = collect($groups)->pluck('ids')->flatten()->values()->all();
        $token = Crypt::encryptString(json_encode(['type' => 'prompt-bulk-undo', 'brand' => $brand->id, 'tenant' => current_tenant_id(), 'groups' => array_values($groups)], JSON_THROW_ON_ERROR));

        return new BulkResult(count($ids), $ids === [] ? null : ['ids' => $ids, 'action' => 'undo', 'value' => $token]);
    }
}

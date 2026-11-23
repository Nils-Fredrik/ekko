<?php

namespace App\Inbox;

use App\Models\Action;
use App\Models\ActionPrompt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Reverses only the inbox edit that issued the receipt, under the original user's current permissions. */
final class InboxActionUndo
{
    /** @return array{attributes: array<string, mixed>, prompts: list<int>}|null */
    public function before(Action $action): ?array
    {
        return $action->wasRecentlyCreated ? null : [
            'attributes' => $action->getRawOriginal(),
            'prompts' => $this->prompts($action),
        ];
    }

    /** Called with the action locked, before the mutation commits.
     *
     * @param  array{attributes: array<string, mixed>, prompts: list<int>}|null  $before
     */
    public function receipt(Action $action, ?array $before, int $userId): ?string
    {
        $current = Action::query()->findOrFail($action->id);
        $prompts = $this->prompts($current);
        $restore = [];
        if ($before !== null) {
            foreach ($before['attributes'] as $key => $value) {
                if ($key !== 'updated_at' && $value !== $current->getRawOriginal($key)) {
                    $restore[$key] = $value;
                }
            }
            if ($restore === [] && $prompts === $before['prompts']) {
                return null;
            }
        }

        $token = (string) Str::uuid();
        Cache::put($this->key($current, $userId, $token), [
            'version' => $this->version($current),
            'prompts' => $prompts,
            'delete' => $before === null,
            'restore' => $restore,
            'restore_prompts' => $before !== null && $prompts !== $before['prompts'] ? $before['prompts'] : null,
        ], now()->addMinutes(5));

        return $token;
    }

    public function undo(Action $action, int $userId, string $token): void
    {
        DB::transaction(function () use ($action, $userId, $token) {
            $locked = Action::query()->lockForUpdate()->findOrFail($action->id);
            $key = $this->key($locked, $userId, $token);
            $receipt = Cache::get($key);
            abort_unless(is_array($receipt) && $receipt['version'] === $this->version($locked)
                && $receipt['prompts'] === $this->prompts($locked), 409);
            if ($receipt['delete']) {
                $locked->delete();
            } else {
                $locked->setRawAttributes(array_replace($locked->getAttributes(), $receipt['restore']));
                $locked->save();
                if ($receipt['restore_prompts'] !== null) {
                    $locked->prompts()->syncWithPivotValues($receipt['restore_prompts'], ['tenant_id' => $locked->tenant_id]);
                }
            }
            Cache::forget($key);
        });
    }

    private function key(Action $action, int $userId, string $token): string
    {
        return "inbox.undo.{$action->tenant_id}.{$userId}.{$action->id}.{$token}";
    }

    /** The PostgreSQL tuple changes even for two edits within the same second or transaction. */
    private function version(Action $action): string
    {
        return (string) Action::query()->whereKey($action->id)->toBase()
            ->selectRaw("xmin::text || ':' || ctid::text AS version")->first()->version;
    }

    /** @return list<int> */
    private function prompts(Action $action): array
    {
        return ActionPrompt::query()->where('action_id', $action->id)->orderBy('prompt_id')->pluck('prompt_id')->all();
    }
}

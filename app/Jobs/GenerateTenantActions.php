<?php

namespace App\Jobs;

use App\ActionEngine\ActionFormulator;
use App\ActionEngine\ActionGenerator;
use App\ActionEngine\ActionPromptBuilder;
use App\Jobs\Middleware\SetTenantContext;
use App\Models\Action;
use App\Models\Brand;
use App\Tenancy\Rls\TenantRls;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * The weekly action generation of one tenant (docs/spec.md 11.3, ADR 0034). Each brand is generated in its own
 * transaction under row-level security; then the new actions are worded by the model one at a time, outside any
 * transaction (a model call can take many seconds), at most ekko.actions.max_formulations per run and no call
 * started after ekko.actions.word_seconds (or the command's deadline), so the job stays within the worker and
 * Cloud Run task timeouts. An action the
 * model could not word keeps its template text and is tried again at the next generation.
 *
 * Like EvaluateTenantAlerts it overrides middleware() and sets row-level security itself.
 */
class GenerateTenantActions extends TenantJob
{
    /** A paid call is never repeated by a retry; what is not worded waits for the next generation. */
    public int $tries = 1;

    /**
     * @param  int|null  $wordUntil  Unix time after which no model call is started (the command's deadline)
     */
    public function __construct(int $tenantId, public readonly bool $force = false, public readonly ?int $wordUntil = null)
    {
        parent::__construct($tenantId);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new SetTenantContext];
    }

    public function handle(ActionGenerator $generator, ActionFormulator $formulator, ActionPromptBuilder $prompts, TenantRls $rls): void
    {
        $now = CarbonImmutable::now();
        $brandIds = $rls->run([$this->tenantId], fn (): array => Brand::query()->where('status', 'active')->orderBy('id')->pluck('id')->all());

        $toWord = [];
        foreach ($brandIds as $brandId) {
            $result = $rls->run([$this->tenantId], function () use ($generator, $brandId, $now) {
                $brand = Brand::query()->find($brandId);

                return $brand === null ? null : $generator->generate($brand, $now, $this->force);
            });
            if ($result !== null) {
                $toWord = [...$toWord, ...$result->toWord];
                Log::info('Actions generated.', ['brand' => $brandId, 'created' => $result->created, 'refreshed' => $result->refreshed, 'reopened' => $result->reopened, 'expired' => $result->expired]);
            }
        }

        if (! $formulator->available()) {
            return;
        }
        $deadline = time() + max(0, (int) config('ekko.actions.word_seconds'));
        if ($this->wordUntil !== null) {
            $deadline = min($deadline, $this->wordUntil);
        }
        foreach (array_slice(array_values(array_unique($toWord)), 0, max(0, (int) config('ekko.actions.max_formulations'))) as $actionId) {
            if (time() >= $deadline) {
                Log::info('Action wording stopped at the time limit.', ['tenant' => $this->tenantId]);
                break;
            }
            $request = $rls->run([$this->tenantId], function () use ($prompts, $actionId) {
                $action = Action::query()->with(['brand', 'market', 'topic'])->find($actionId);

                return $action === null || $action->brand === null ? null : $prompts->build($action, $action->brand);
            });
            if ($request === null) {
                continue;
            }

            $text = $formulator->formulate($this->tenantId, $request);
            if ($text === null) {
                continue;
            }

            $rls->run([$this->tenantId], function () use ($actionId, $text): void {
                $action = Action::query()->find($actionId);
                // The user may have acted on it meanwhile; only a new action with the template text is replaced.
                if ($action === null || $action->text_source !== 'template' || $action->status->value !== 'new') {
                    return;
                }
                $action->forceFill([
                    'title' => $text->title,
                    'description' => $text->description,
                    'steps' => $text->stepRows(),
                    'text_source' => 'model',
                    'locale' => ActionPromptBuilder::languageTag($action),
                ])->save();
            });
        }
    }
}

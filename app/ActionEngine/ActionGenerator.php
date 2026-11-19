<?php

namespace App\ActionEngine;

use App\Enums\ActionStatus;
use App\Models\Action;
use App\Models\ActionGeneration;
use App\Models\ActionPrompt;
use App\Models\Brand;
use App\Models\Market;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The weekly generation of one brand (docs/spec.md 11.3, ADR 0034). Runs in the tenant's context under row-level
 * security, in one transaction, and makes no model call.
 *
 * - Every source judges the brand's markets and returns candidates with evidence read from the database.
 * - A candidate is found again by its fingerprint. An open action gets the new evidence, effect and prompts; an
 *   expired one opens again as new; done and declined actions are left as the user left them. A candidate without
 *   an action becomes a new action with the template text, to be worded by the model afterwards.
 * - A new or planned system action that a source could judge but did not find counts one unsupported generation;
 *   after ekko.actions.expire_after_generations in a row it expires. Actions in progress do not expire: the work
 *   may be why the data changed. A scope a source could not judge is left alone.
 * - One generation per brand and local ISO week counts: a second run the same week refreshes but does not count.
 */
final class ActionGenerator
{
    /**
     * @param  list<ActionSource>|null  $sources
     */
    public function __construct(private ?array $sources = null) {}

    public function generate(Brand $brand, CarbonImmutable $now, bool $force = false): ?GenerationResult
    {
        $tenant = Team::query()->find(current_tenant_id());
        $timezone = $this->timezone((string) ($tenant->timezone ?? 'UTC'));
        $today = $now->setTimezone($timezone)->startOfDay();

        $counts = $this->claimWeek($brand, $today, $now);
        if (! $counts && ! $force) {
            return null;
        }

        $markets = Market::query()->where('brand_id', $brand->id)->where('is_active', true)->orderBy('id')->get();
        $context = new GenerationContext($brand, $markets, $today);
        $created = $refreshed = $reopened = $expired = 0;
        $toWord = [];

        foreach ($this->sources() as $source) {
            $outcome = $source->evaluate($context);
            $seen = [];
            foreach ($outcome->candidates as $candidate) {
                if ($candidate->type !== $source->type()) {
                    continue;
                }
                $fingerprint = $candidate->fingerprint($brand->id);
                if (isset($seen[$fingerprint])) {
                    continue;
                }
                $seen[$fingerprint] = true;

                $action = Action::query()->where('fingerprint', $fingerprint)->first();
                if ($action === null) {
                    $action = $this->create($brand, $candidate, $fingerprint, $markets->firstWhere('id', $candidate->marketId), $tenant, $now);
                    $created++;
                    $toWord[] = $action->id;

                    continue;
                }
                if ($action->status === ActionStatus::Expired) {
                    $action->status = ActionStatus::New;
                    $this->refresh($action, $candidate, $now);
                    $reopened++;
                } elseif ($action->status->isOpen()) {
                    $this->refresh($action, $candidate, $now);
                    $refreshed++;
                }
                if ($action->status === ActionStatus::New && $action->text_source === 'template') {
                    $toWord[] = $action->id;
                }
            }

            $supported = [];
            foreach ($outcome->alsoSupported as $finding) {
                $fingerprint = ActionCandidate::fingerprintOf($source->type(), $brand->id, $finding['market_id'], $finding['subject']);
                $seen[$fingerprint] = true;
                $supported[] = $fingerprint;
            }
            if ($supported !== []) {
                // Supported again: expiry needs two unsupported generations in a row. The evidence is left as it was.
                Action::query()->whereIn('fingerprint', $supported)->where('unsupported_generations', '>', 0)
                    ->whereIn('status', [ActionStatus::New->value, ActionStatus::Planned->value, ActionStatus::InProgress->value])
                    ->update(['unsupported_generations' => 0]);
            }
            if ($counts) {
                $expired += $this->countUnsupported($brand, $source, $outcome, array_keys($seen));
            }
        }

        return new GenerationResult($created, $refreshed, $reopened, $expired, $toWord);
    }

    /**
     * True when this run is the brand's generation for the local ISO week (the unique key decides a race).
     */
    private function claimWeek(Brand $brand, CarbonImmutable $today, CarbonImmutable $now): bool
    {
        try {
            DB::transaction(fn () => ActionGeneration::query()->create([
                'brand_id' => $brand->id,
                'week_start' => $today->startOfWeek(CarbonImmutable::MONDAY)->toDateString(),
                'generated_at' => $now->utc(),
            ]));
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    private function create(Brand $brand, ActionCandidate $candidate, string $fingerprint, ?Market $market, ?Team $tenant, CarbonImmutable $now): Action
    {
        $locale = ActionLocale::appLocale(ActionLocale::tag($market, $tenant));
        $text = ActionTemplates::text($candidate->type, $candidate->textParams, $locale);

        $action = new Action([
            'brand_id' => $brand->id,
            'market_id' => $candidate->marketId,
            'type' => $candidate->type,
            'status' => ActionStatus::New,
            'created_by' => Action::CREATED_BY_SYSTEM,
            'fingerprint' => $fingerprint,
            'title' => $text->title,
            'description' => $text->description,
            'steps' => $text->stepRows(),
            'text_source' => 'template',
            'locale' => $locale,
        ]);
        $this->fill($action, $candidate, $now);
        $action->save();
        $this->syncPrompts($action, $candidate);

        return $action;
    }

    private function refresh(Action $action, ActionCandidate $candidate, CarbonImmutable $now): void
    {
        $this->fill($action, $candidate, $now);
        if ($action->text_source === 'template') {
            // The template holds figures; keep them current. Model and user texts hold none.
            $text = ActionTemplates::text($candidate->type, $candidate->textParams, $action->locale);
            $action->title = $text->title;
            $action->description = $text->description;
            if ($action->status === ActionStatus::New) {
                $action->steps = $text->stepRows();
            }
        }
        $action->save();
        $this->syncPrompts($action, $candidate);
    }

    private function fill(Action $action, ActionCandidate $candidate, CarbonImmutable $now): void
    {
        $score = $candidate->impactScore();
        $action->fill([
            'topic_id' => $candidate->topicId,
            'evidence' => [...$candidate->evidence, 'text_params' => $candidate->textParams],
            'competitor_evidence' => $candidate->competitorEvidence,
            'engine_keys' => $candidate->engineKeys,
            'impact_score' => $score,
            'impact_level' => ImpactScale::level($score),
            'unsupported_generations' => 0,
            'last_generated_at' => $now->utc(),
        ]);
    }

    private function syncPrompts(Action $action, ActionCandidate $candidate): void
    {
        ActionPrompt::query()->where('action_id', $action->id)->delete();
        $rows = array_map(fn (int $promptId): array => ['action_id' => $action->id, 'prompt_id' => $promptId, 'tenant_id' => $action->tenant_id], array_keys($candidate->prompts));
        if ($rows !== []) {
            ActionPrompt::query()->insert($rows);
        }
    }

    /**
     * @param  list<string>  $seen  fingerprints the source produced this generation
     */
    private function countUnsupported(Brand $brand, ActionSource $source, SourceOutcome $outcome, array $seen): int
    {
        if ($outcome->evaluatedMarkets === []) {
            return 0;
        }
        $markets = array_values(array_filter($outcome->evaluatedMarkets, fn (?int $id): bool => $id !== null));
        $brandLevel = in_array(null, $outcome->evaluatedMarkets, true);

        $stale = Action::query()
            ->where('brand_id', $brand->id)
            ->where('type', $source->type()->value)
            ->where('created_by', Action::CREATED_BY_SYSTEM)
            ->whereIn('status', [ActionStatus::New->value, ActionStatus::Planned->value])
            ->whereNotIn('fingerprint', $seen)
            ->where(fn ($q) => $q
                ->when($markets !== [], fn ($q) => $q->orWhereIn('market_id', $markets))
                ->when($brandLevel, fn ($q) => $q->orWhereNull('market_id')))
            ->get();

        $limit = max(1, (int) config('ekko.actions.expire_after_generations'));
        $expired = 0;
        foreach ($stale as $action) {
            $action->unsupported_generations++;
            if ($action->unsupported_generations >= $limit) {
                $action->status = ActionStatus::Expired;
                $expired++;
            }
            $action->save();
        }

        return $expired;
    }

    /**
     * @return list<ActionSource>
     */
    private function sources(): array
    {
        return $this->sources ??= array_values(array_map(
            fn (string $class): ActionSource => app($class),
            (array) config('ekko.actions.sources'),
        ));
    }

    private function timezone(string $name): string
    {
        return in_array($name, timezone_identifiers_list(), true) ? $name : 'UTC';
    }
}

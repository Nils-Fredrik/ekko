<?php

namespace App\Prompts;

use App\Detection\EntityName;
use App\Jobs\ReclassifyEntityMentions;
use App\Models\Brand;
use App\Models\BrandAlias;
use App\Models\Competitor;
use App\Models\CompetitorAlias;
use App\Models\EntityDecision;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Decides and undoes decisions on unknown brands (docs/ux-arbeidsflate.md 6.3, ADR 0043).
 *
 * - competitor: creates the competitor (or restores an archived one with the same key) with every variant as a
 *   case-insensitive alias, within the pitch limit, and moves old mentions to it.
 * - alias: adds the variants as case-insensitive aliases of the brand or a competitor and moves old mentions.
 * - other and ignored: only stored. The name leaves the queue, alerts and actions.
 *
 * An undo within ekko.entities.undo_hours removes the aliases the decision added, archives the competitor it
 * created or restored, moves the mentions back to unknown and deletes the decision.
 */
final readonly class EntityDecisions
{
    public function __construct(private EntityQueue $queue) {}

    /**
     * @param  list<string>  $keys
     * @return list<EntityDecision>
     *
     * @throws ValidationException
     * @throws AuthorizationException when an alias of the brand is decided without brands.manage
     */
    public function decide(Brand $brand, array $keys, string $decision, ?string $targetType, ?int $targetId, ?User $user): array
    {
        $decisions = DB::transaction(function () use ($brand, $keys, $decision, $targetType, $targetId, $user): array {
            // Conversion and onboarding take this same lock before changing pitch state; the brand lock keeps two
            // decisions on the same names from both passing the checks.
            $tenant = Team::query()->whereKey($brand->getAttribute('tenant_id'))->lock('for no key update')->firstOrFail();
            Brand::query()->whereKey($brand->id)->lockForUpdate()->first();

            $keys = array_values(array_unique($keys));
            $found = $this->queue->lookup($brand, $keys);
            $known = EntityQueue::knownKeys($brand);
            $decided = EntityQueue::decidedKeys($brand);
            $errors = [];
            foreach ($keys as $index => $key) {
                $error = match (true) {
                    isset($decided[$key]) => 'entities.errors.decided',
                    isset($known[$key]) => 'entities.errors.known',
                    ! isset($found[$key]) => 'entities.errors.not_found',
                    default => null,
                };
                if ($error !== null) {
                    $errors["keys.{$index}"] = __($error, ['days' => (int) config('ekko.entities.reclassify_days')]);
                }
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $target = $decision === EntityDecision::ALIAS ? $this->target($brand, $targetType, $targetId) : null;

            $created = [];
            foreach ($keys as $key) {
                $created[] = match ($decision) {
                    EntityDecision::COMPETITOR => $this->competitor($tenant, $brand, $key, $found[$key], $user),
                    EntityDecision::ALIAS => $this->alias($brand, $key, $found[$key], $target, $user),
                    default => $this->record($brand, $key, $found[$key], $decision, $user),
                };
            }

            if ($this->queue->pending($brand) === []) {
                $brand->forceFill(['entities_reviewed_at' => now()])->save();
            }

            return $created;
        });

        foreach ($decisions as $record) {
            if ($record->target_type !== null && $record->target_id !== null) {
                ReclassifyEntityMentions::dispatch((int) $record->getAttribute('tenant_id'), $brand->id, $record->name_key, $record->target_type, $record->target_id, $record->id);
            }
        }

        return $decisions;
    }

    /**
     * @throws ValidationException when the decision is older than ekko.entities.undo_hours
     */
    public function undo(EntityDecision $decision): void
    {
        $revert = DB::transaction(function () use ($decision): ?array {
            $locked = EntityDecision::query()->whereKey($decision->id)->lockForUpdate()->firstOrFail();
            if (! $locked->undoable()) {
                throw ValidationException::withMessages(['decision' => __('entities.errors.undo_expired', ['hours' => (int) config('ekko.entities.undo_hours')])]);
            }

            if ($locked->target_type !== null && $locked->target_id !== null) {
                $aliases = $locked->target_type === 'brand'
                    ? BrandAlias::query()->where('brand_id', $locked->target_id)
                    : CompetitorAlias::query()->where('competitor_id', $locked->target_id);
                // One by one, so each removal is in the revision history.
                $aliases->whereIn('id', $locked->alias_ids)->get()->each(fn (Model $alias) => $alias->delete());

                if ($locked->created_competitor) {
                    Competitor::query()->where('brand_id', $locked->brand_id)->whereKey($locked->target_id)->first()?->delete();
                }
            }

            $revert = $locked->target_type !== null && $locked->target_id !== null
                ? [(int) $locked->getAttribute('tenant_id'), (int) $locked->brand_id, $locked->name_key, $locked->target_type, (int) $locked->target_id]
                : null;
            $locked->delete();

            return $revert;
        });

        if ($revert !== null) {
            [$tenantId, $brandId, $key, $type, $id] = $revert;
            ReclassifyEntityMentions::dispatch($tenantId, $brandId, $key, $type, $id, null, true);
        }
    }

    /** @param  array{name: string, variants: list<string>}  $found */
    private function competitor(Team $tenant, Brand $brand, string $key, array $found, ?User $user): EntityDecision
    {
        // An archived competitor with the same key comes back, at the end of the color order.
        $archived = Competitor::onlyTrashed()->where('brand_id', $brand->id)->orderByDesc('deleted_at')->get()
            ->first(fn (Competitor $competitor): bool => EntityName::key($competitor->name) === $key);

        if ($tenant->kind === 'pitch' && $brand->competitors()->count() >= 3) {
            throw ValidationException::withMessages(['keys' => __('pitch.limits')]);
        }

        if ($archived !== null) {
            $archived->restore();
            $archived->forceFill(['is_active' => true, 'position' => Competitor::nextPosition($brand->id)])->save();
            $competitor = $archived;
        } else {
            $competitor = $brand->competitors()->create(['name' => self::display($found['name']), 'is_active' => true]);
        }

        $aliasIds = $this->addAliases($competitor, $found['variants']);

        return $this->record($brand, $key, $found, EntityDecision::COMPETITOR, $user, [
            'target_type' => 'competitor',
            'target_id' => $competitor->id,
            'created_competitor' => true,
            'alias_ids' => $aliasIds,
        ]);
    }

    /** @param  array{name: string, variants: list<string>}  $found */
    private function alias(Brand $brand, string $key, array $found, Brand|Competitor|null $target, ?User $user): EntityDecision
    {
        assert($target !== null);

        return $this->record($brand, $key, $found, EntityDecision::ALIAS, $user, [
            'target_type' => $target instanceof Brand ? 'brand' : 'competitor',
            'target_id' => $target->id,
            'alias_ids' => $this->addAliases($target, $found['variants']),
        ]);
    }

    /**
     * @param  array{name: string, variants: list<string>}  $found
     * @param  array<string, mixed>  $effect
     */
    private function record(Brand $brand, string $key, array $found, string $decision, ?User $user, array $effect = []): EntityDecision
    {
        return EntityDecision::query()->create([
            'brand_id' => $brand->id,
            'name_key' => $key,
            'display_name' => self::display($found['name']),
            'variants' => $found['variants'],
            'decision' => $decision,
            'target_type' => null,
            'target_id' => null,
            'created_competitor' => false,
            'alias_ids' => [],
            'reclassified_mentions' => 0,
            'decided_by' => $user?->id,
            'decided_at' => now(),
            ...$effect,
        ]);
    }

    /**
     * Adds each variant the target does not already have (compared without case) as a case-insensitive alias.
     *
     * @param  list<string>  $variants
     * @return list<int> the new aliases
     */
    private function addAliases(Brand|Competitor $target, array $variants): array
    {
        $existing = [];
        foreach ($target->aliases()->where('match_mode', '!=', 'regex')->pluck('alias') as $alias) {
            $existing[mb_strtolower((string) $alias)] = true;
        }

        $ids = [];
        foreach ($variants as $variant) {
            $alias = mb_substr(trim($variant), 0, 255);
            if ($alias === '' || isset($existing[mb_strtolower($alias)])) {
                continue;
            }
            $existing[mb_strtolower($alias)] = true;
            $ids[] = (int) $target->aliases()->create(['alias' => $alias, 'match_mode' => 'case_insensitive'])->id;
        }

        return $ids;
    }

    /** @throws ValidationException */
    private function target(Brand $brand, ?string $type, ?int $id): Brand|Competitor
    {
        $target = match ($type) {
            'brand' => $id === $brand->id ? $brand : null,
            'competitor' => $id === null ? null : Competitor::query()->where('brand_id', $brand->id)->whereKey($id)->first(),
            default => null,
        };
        if ($target === null) {
            throw ValidationException::withMessages(['target_id' => __('entities.errors.target')]);
        }
        // The brand's own aliases are the brand's settings, so an alias of the brand also needs brands.manage.
        if ($target instanceof Brand) {
            Gate::authorize('update', $target);
        }

        return $target;
    }

    private static function display(string $name): string
    {
        return mb_substr(trim($name), 0, 200);
    }
}

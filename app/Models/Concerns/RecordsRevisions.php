<?php

namespace App\Models\Concerns;

use App\Tenancy\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Writes creates, changes and deletes of a tenant-owned model to the revision log (docs/spec.md 16),
 * log name "audit", in the model's tenant and under RLS like the model itself.
 *
 * Only the attributes in revisionAttributes() are logged. Attributes in maskedRevisionAttributes()
 * are logged as changed, never with their value: secrets (integration config) and free text that
 * must not be copied into logs (prompt texts).
 *
 * @mixin Model
 */
trait RecordsRevisions
{
    use LogsActivity;

    public const REVISION_MASK = '[changed]';

    /**
     * @return list<string>
     */
    abstract protected function revisionAttributes(): array;

    /**
     * @return list<string>
     */
    protected function maskedRevisionAttributes(): array
    {
        return [];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(AuditLog::LOG_NAME)
            ->logOnly([...$this->revisionAttributes(), ...$this->maskedRevisionAttributes()])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function beforeActivityLogged(Model $activity, string $eventName): void
    {
        $masked = $this->maskedRevisionAttributes();
        $changes = $activity->getAttribute('attribute_changes');

        // Cast to a collection of "attributes" and "old", each an array of attribute => value.
        if ($masked === [] || ! $changes instanceof Collection) {
            return;
        }

        $changes = $changes->map(function (mixed $values) use ($masked): mixed {
            if (! is_array($values)) {
                return $values;
            }

            foreach ($masked as $attribute) {
                if (array_key_exists($attribute, $values)) {
                    $values[$attribute] = self::REVISION_MASK;
                }
            }

            return $values;
        });

        $activity->setAttribute('attribute_changes', $changes);
    }
}

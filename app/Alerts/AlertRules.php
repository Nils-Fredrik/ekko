<?php

namespace App\Alerts;

use App\Models\AlertRule;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AlertRules
{
    /** Use a saved rule without changing it. Older installations can have a brand-specific integration rule. */
    public function forSource(AlertType $type, ?int $brandId): AlertRule
    {
        $existing = $this->find($type, $brandId);
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($type, $brandId) {
            Team::query()->lockForUpdate()->findOrFail(current_tenant_id());

            return $this->find($type, $brandId) ?? AlertRule::create([
                'brand_id' => $type->isTenantWide() ? null : $brandId, 'type' => $type->value, 'is_active' => true,
                'threshold' => $type->defaultThreshold(),
                'channels' => $this->defaultChannels($type),
            ]);
        });
    }

    /** @return list<string> */
    private function defaultChannels(AlertType $type): array
    {
        return $type === AlertType::IntegrationError ? ['in_app'] : config()->array('ekko.alerts.default_channels');
    }

    private function find(AlertType $type, ?int $brandId): ?AlertRule
    {
        $query = AlertRule::where('type', $type->value);
        if ($type === AlertType::IntegrationError && $brandId !== null) {
            return $query->where(fn ($q) => $q->whereNull('brand_id')->orWhere('brand_id', $brandId))
                ->orderByRaw('brand_id IS NULL')->orderBy('id')->first();
        }

        return $query->where('brand_id', $type->isTenantWide() ? null : $brandId)->orderBy('id')->first();
    }

    /** @param array<string, mixed> $changes */
    public function save(AlertType $type, ?int $brandId, array $changes, bool $createOnly = false, ?AlertRule $existing = null): AlertRule
    {
        return DB::transaction(function () use ($type, $brandId, $changes, $createOnly, $existing) {
            Team::query()->lockForUpdate()->findOrFail(current_tenant_id());
            $rule = $existing === null
                ? AlertRule::query()->firstOrNew(['type' => $type->value, 'brand_id' => $type->isTenantWide() ? null : $brandId])
                : AlertRule::query()->lockForUpdate()->findOrFail($existing->id);
            if ($createOnly && $rule->exists) {
                throw ValidationException::withMessages(['type' => __('app.workspace.rule_exists')]);
            }
            if (! $rule->exists) {
                $rule->fill(['is_active' => true, 'channels' => $this->defaultChannels($type)]);
            }
            $rule->threshold = array_key_exists('threshold', $changes)
                ? ($changes['threshold'] ?? $type->defaultThreshold()) : ($rule->threshold ?? $type->defaultThreshold());
            if (array_key_exists('is_active', $changes)) {
                $rule->is_active = $changes['is_active'];
            }
            if (array_key_exists('channels', $changes)) {
                $rule->channels = array_values(array_unique(['inbox', ...$changes['channels']]));
            }
            $rule->save();
            if ($type === AlertType::IntegrationError && $rule->brand_id === null) {
                // Historical alerts still reference their brand rule. Keep it effective and apply only
                // the fields the administrator explicitly changed for the tenant.
                $fields = array_intersect_key($rule->getAttributes(), $changes, array_flip(['channels', 'is_active', 'threshold']));
                if ($fields !== []) {
                    AlertRule::where('type', $type->value)->whereNotNull('brand_id')->update($fields);
                }
            }

            return $rule;
        });
    }
}

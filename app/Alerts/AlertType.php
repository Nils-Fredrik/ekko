<?php

namespace App\Alerts;

/**
 * The alert types of docs/spec.md 11.1. The values are the alert_rules.type values.
 */
enum AlertType: string
{
    case VisibilityDrop = 'visibility_drop';
    case CompetitorTakeover = 'competitor_takeover';
    case CitationLost = 'citation_lost';
    case NewCompetitor = 'new_competitor';
    case ModelChange = 'model_change';
    case Quota = 'quota';
    case IntegrationError = 'integration_error';

    /**
     * Types about the whole tenant, not one brand: their rule has no brand_id.
     */
    public function isTenantWide(): bool
    {
        return in_array($this, [self::ModelChange, self::Quota, self::IntegrationError], true);
    }

    /**
     * Default threshold for a new rule, in the unit of alert_rules.threshold (null: the type has none).
     */
    public function defaultThreshold(): ?float
    {
        $value = config("ekko.alerts.thresholds.{$this->value}");

        return $value === null ? null : (float) $value;
    }

    public function cooldownDays(): int
    {
        return (int) config("ekko.alerts.cooldown_days.{$this->value}", config('ekko.alerts.cooldown_days.default'));
    }

    public function minResponses(): int
    {
        return (int) config("ekko.alerts.min_responses.{$this->value}", 1);
    }
}

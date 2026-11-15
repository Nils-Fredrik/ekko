<?php

namespace App\Alerts;

use App\Dashboard\DashboardLabels;
use App\Models\Alert;
use App\Models\Competitor;
use App\Models\Market;
use App\Models\Prompt;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;

/**
 * Turns alerts into the sentences of a digest, in a given language (lang/{locale}/alerts.php). The words for
 * engines and alert types are the dashboard's (DashboardLabels).
 */
final class AlertPresenter
{
    /** @var array<int, string> */
    private array $markets = [];

    /** @var array<int, string> */
    private array $competitors = [];

    /** @var array<int, string> */
    private array $prompts = [];

    /**
     * Alerts grouped by type, in the order of the alert types, each group with its heading.
     *
     * @param  iterable<Alert>  $alerts
     * @return list<array{title: string, lines: list<string>}>
     */
    public function groups(iterable $alerts, string $locale): array
    {
        $byType = [];
        foreach ($alerts as $alert) {
            $byType[$alert->alertRule->type][] = $alert;
        }

        $previous = App::getLocale();
        App::setLocale($locale);

        try {
            $groups = [];
            foreach (AlertType::cases() as $type) {
                if (! isset($byType[$type->value])) {
                    continue;
                }
                $groups[] = [
                    'title' => DashboardLabels::alertType($type->value),
                    'lines' => array_map(fn (Alert $alert): string => $this->line($alert, $type), $byType[$type->value]),
                ];
            }

            return $groups;
        } finally {
            App::setLocale($previous);
        }
    }

    /**
     * The sentence for one alert, in the application locale.
     */
    private function line(Alert $alert, AlertType $type): string
    {
        $payload = $alert->payload;
        $brand = $alert->alertRule->brand_id === null ? '' : $alert->alertRule->brand->name;

        return match ($type) {
            AlertType::VisibilityDrop => __('alerts.lines.visibility_drop', [
                'brand' => $brand,
                'market' => $this->market($payload['market_id'] ?? null),
                'engine' => DashboardLabels::engine((string) ($payload['engine_key'] ?? '')),
                'from' => $this->percent($payload['previous']['value'] ?? null),
                'to' => $this->percent($payload['current']['value'] ?? null),
                'points' => (string) round((float) ($payload['drop_points'] ?? 0)),
                'n_from' => (string) ($payload['previous']['n'] ?? 0),
                'n_to' => (string) ($payload['current']['n'] ?? 0),
            ]),
            AlertType::CompetitorTakeover => __('alerts.lines.competitor_takeover', [
                'brand' => $brand,
                'competitor' => $this->competitor($payload['competitor_id'] ?? null),
                'prompt' => $this->prompt($payload['prompt_id'] ?? null),
                'from' => $this->percent($payload['previous']['value'] ?? null),
                'to' => $this->percent($payload['current']['value'] ?? null),
                'n' => (string) ($payload['current']['n'] ?? 0),
            ]),
            AlertType::CitationLost => __('alerts.lines.citation_lost', [
                'brand' => $brand,
                'prompt' => $this->prompt($payload['prompt_id'] ?? null),
                'engine' => DashboardLabels::engine((string) ($payload['engine_key'] ?? '')),
                'date' => (string) ($payload['previous_round']['date'] ?? ''),
            ]),
            AlertType::NewCompetitor => __('alerts.lines.new_competitor', [
                'brand' => $brand,
                'name' => (string) ($payload['name'] ?? ''),
                'market' => $this->market($payload['market_id'] ?? null),
                'share' => $this->percent($payload['share']['value'] ?? null),
                'n' => (string) ($payload['share']['n'] ?? 0),
            ]),
            AlertType::ModelChange => __('alerts.lines.model_change', [
                'engine' => DashboardLabels::engine((string) ($payload['engine_key'] ?? '')),
                'from' => (string) ($payload['from_model'] ?? ''),
                'to' => (string) ($payload['to_model'] ?? ''),
                'date' => (string) ($payload['date'] ?? ''),
            ]),
            AlertType::Quota => __('alerts.lines.quota', [
                'percent' => (string) ($payload['threshold_percent'] ?? ''),
                'month' => substr((string) ($payload['month'] ?? ''), 0, 7),
            ]),
            AlertType::IntegrationError => __('alerts.lines.integration_error', [
                'brand' => $brand,
                'integration' => __('alerts.integrations.'.($payload['integration_type'] ?? 'unknown')),
            ]),
        };
    }

    private function percent(mixed $value): string
    {
        return is_numeric($value) ? (string) round((float) $value * 100) : '?';
    }

    private function market(mixed $id): string
    {
        if (! is_numeric($id)) {
            return '';
        }

        $market = Market::query()->find((int) $id);

        return $this->markets[(int) $id] ??= $market === null ? '' : ($market->location_label ?: strtoupper($market->country));
    }

    private function competitor(mixed $id): string
    {
        return is_numeric($id) ? ($this->competitors[(int) $id] ??= (string) Competitor::query()->whereKey((int) $id)->value('name')) : '';
    }

    private function prompt(mixed $id): string
    {
        return is_numeric($id) ? ($this->prompts[(int) $id] ??= Str::limit((string) Prompt::query()->whereKey((int) $id)->value('text'), 80)) : '';
    }
}

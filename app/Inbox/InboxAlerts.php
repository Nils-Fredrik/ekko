<?php

namespace App\Inbox;

use App\Alerts\AlertPresenter;
use App\Alerts\AlertType;
use App\Models\Alert;
use App\Models\Brand;
use App\Models\Integration;
use App\Models\Market;
use App\Models\Prompt;
use App\Models\Team;
use App\Workspace\Workspace;

final readonly class InboxAlerts
{
    public const DAYS = 30;

    public function __construct(private AlertPresenter $presenter) {}

    /** @return list<InboxItem> */
    public function items(Team $team, ?Brand $brand, ?Market $market, bool $handled): array
    {
        $alerts = Alert::query()->with('alertRule.brand')->where('triggered_at', '>=', now()->subDays(self::DAYS))
            ->when(! $handled, fn ($q) => $q->whereNull('acknowledged_at'))
            ->orderByDesc('triggered_at')->orderByDesc('id')->get();
        $promptMarkets = Prompt::whereIn('id', $alerts->pluck('payload.prompt_id')->filter())->pluck('market_id', 'id');
        $integrationBrands = Integration::whereIn('id', $alerts->pluck('payload.integration_id')->filter())->pluck('brand_id', 'id');
        $brands = Brand::whereIn('id', $integrationBrands->merge($alerts->pluck('payload.brand_id'))->filter())->get()->keyBy('id');
        $markets = Market::with('brand')->whereIn('id', $alerts->pluck('payload.market_id')->merge($promptMarkets->values())->filter())->get()->keyBy('id');

        $groups = [];
        foreach ($alerts as $alert) {
            $payload = $alert->payload;
            $owner = $alert->alertRule->brand ?? $brands->get($payload['brand_id'] ?? $integrationBrands->get($payload['integration_id'] ?? 0));
            $scope = $markets->get($payload['market_id'] ?? $promptMarkets->get($payload['prompt_id'] ?? 0));
            $owner ??= $scope?->brand;
            if ($owner?->status === 'archived') {
                continue;
            }
            if (($brand && $owner && $owner->id !== $brand->id) || ($market && $scope && $scope->id !== $market->id)) {
                continue;
            }
            $day = $alert->triggered_at->copy()->setTimezone($team->timezone)->toDateString();
            $group = implode(':', [$owner?->id, $alert->alertRule->type, $day, (int) ($alert->acknowledged_at !== null)]);
            $groups[$group][] = [$alert, $owner, $scope];
        }
        $items = [];
        foreach ($groups as $rows) {
            $model = $rows[0][0]->alertRule->type === AlertType::ModelChange->value;
            $chunks = count($rows) > 2 || $model ? [$rows] : array_chunk($rows, 1);
            foreach ($chunks as $chunk) {
                [$alert, $owner, $scope] = $chunk[0];
                $rowsInGroup = collect($chunk);
                $scope = $rowsInGroup->pluck('2.id')->unique()->count() === 1 ? $scope : null;
                $labels = $rowsInGroup->pluck(2)->filter()->unique('id')->map(fn (Market $item) => Workspace::marketLabel($item))->values()->all();
                $items[] = $this->row($alert, $owner, $scope, $rowsInGroup->pluck(0)->all(), $labels);
            }
        }

        return $items;
    }

    /**
     * @param  list<Alert>  $alerts
     * @param  list<string>  $labels
     */
    private function row(Alert $alert, ?Brand $brand, ?Market $market, array $alerts, array $labels): InboxItem
    {
        $type = $alert->alertRule->type;
        $title = $this->presenter->title($alert);
        $ids = array_map(fn (Alert $member) => $member->id, $alerts);
        $group = count($ids) > 1;
        if ($group) {
            $title = __('inbox.group_event', ['count' => count($ids), 'event' => $title]);
        }
        $handled = $alert->acknowledged_at !== null;
        $buttons = $handled ? ['open'] : ($group ? ['acknowledge_all', 'show_all'] : ['acknowledge', 'open']);
        $edge = match ($type) {
            'model_change' => 'marker-model',
            'integration_error' => 'error',
            'quota' => collect($alerts)->max('payload.threshold_percent') >= 100 ? 'error' : 'warning',
            default => 'warning',
        };
        $url = match (true) {
            $type === 'quota' => route('usage.index'),
            $type === 'integration_error' && $brand !== null => route('brands.setup.integrations', $brand),
            isset($alert->payload['prompt_id']) && $brand !== null => route('brands.prompts.show', [$brand, $alert->payload['prompt_id']]),
            $brand !== null => route('brands.show', $brand),
            default => route('inbox.index'),
        };

        $engineCount = collect($alerts)->pluck('payload.engine_key')->filter()->unique()->count();
        $context = ['market_labels' => $labels, 'engine_count' => $engineCount ?: null,
            'answers' => $group ? null : ($alert->payload['current']['n'] ?? $alert->payload['share']['n'] ?? null)];
        if ($group) {
            $context['members'] = array_map(fn (Alert $member) => ['id' => $member->id] + $this->presenter->present($member), $alerts);
        }

        return new InboxItem('alert', (string) $alert->id, $brand, $market, $alert->triggered_at, $edge, $title, $group ? '' : $this->basis($alert), $buttons, $url, $handled, $ids, $context);
    }

    private function basis(Alert $alert): string
    {
        $payload = $alert->payload;

        return match (AlertType::from($alert->alertRule->type)) {
            AlertType::Quota => substr((string) ($payload['month'] ?? ''), 0, 7),
            AlertType::ModelChange => implode(' → ', array_filter([$payload['from_model'] ?? null, $payload['to_model'] ?? null])),
            AlertType::VisibilityDrop, AlertType::CompetitorTakeover => isset($payload['previous']['value'], $payload['current']['value'])
                ? round(100 * $payload['previous']['value']).'% → '.round(100 * $payload['current']['value']).'%' : '',
            AlertType::CitationLost => (string) ($payload['previous_round']['date'] ?? ''),
            default => '',
        };
    }
}

<?php

namespace App\Inbox;

use App\Alerts\AlertPresenter;
use App\Alerts\AlertType;
use App\Dashboard\DashboardLabels;
use App\Models\Alert;
use App\Models\Brand;
use App\Models\Integration;
use App\Models\Market;
use App\Models\Prompt;
use App\Models\Team;
use Illuminate\Support\Facades\Route;

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
        $markets = Market::whereIn('id', $alerts->pluck('payload.market_id')->merge($promptMarkets->values())->filter())->get()->keyBy('id');

        $groups = [];
        foreach ($alerts as $alert) {
            $payload = $alert->payload;
            $owner = $alert->alertRule->brand ?? $brands->get($payload['brand_id'] ?? $integrationBrands->get($payload['integration_id'] ?? 0));
            $scope = $markets->get($payload['market_id'] ?? $promptMarkets->get($payload['prompt_id'] ?? 0));
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
                $items[] = $this->row($alert, $owner, $scope, $rowsInGroup->pluck('0')->all());
            }
        }

        return $items;
    }

    /** @param list<Alert> $alerts */
    private function row(Alert $alert, ?Brand $brand, ?Market $market, array $alerts): InboxItem
    {
        $type = $alert->alertRule->type;
        ['title' => $title, 'detail' => $detail] = $this->presenter->present($alert);
        $ids = array_map(fn (Alert $member) => $member->id, $alerts);
        $group = count($ids) > 1;
        if ($group) {
            $title = __('inbox.group', ['count' => count($ids), 'type' => DashboardLabels::alertType($type)]);
            $detail = $market?->location_label ?: ($brand->name ?? '');
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
            $type === 'integration_error' && $brand !== null => route(Route::has('brands.setup.integrations') ? 'brands.setup.integrations' : 'brands.settings', $brand),
            isset($alert->payload['prompt_id']) && $brand !== null => route('brands.prompts.show', [$brand, $alert->payload['prompt_id']]),
            $brand !== null => route('brands.show', $brand),
            default => route('inbox.index'),
        };

        return new InboxItem('alert', (string) $alert->id, $brand, $market, $alert->triggered_at, $edge, $title, $detail, $buttons, $url, $handled, $ids,
            $group ? ['members' => array_map(fn (Alert $member) => ['id' => $member->id] + $this->presenter->present($member), $alerts)] : []);
    }
}

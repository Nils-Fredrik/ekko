<?php

namespace App\MeasurementPlan;

use App\Models\Prompt;
use Illuminate\Support\Facades\DB;

/** Counts and real example IDs, without loading prompt text or inventing IDs for wizard proposals. */
final readonly class PromptPopulation
{
    public function __construct(
        public int $total = 0,
        public int $core = 0,
        public ?int $coreExample = null,
        public ?int $standardExample = null,
    ) {}

    /**
     * @param  list<int>  $marketIds
     * @return array<int, self>
     */
    public static function forMarkets(array $marketIds): array
    {
        $groups = Prompt::query()->whereIn('market_id', $marketIds)->where('is_active', true)
            ->groupBy('market_id', 'priority')->toBase()
            ->get(['market_id', 'priority', DB::raw('count(*) as count, min(id) as example')])->groupBy('market_id');
        $populations = [];
        foreach ($groups as $id => $rows) {
            $core = $rows->firstWhere('priority', 'core');
            $standard = $rows->firstWhere('priority', 'standard');
            $populations[$id] = new self((int) ($core->count ?? 0) + (int) ($standard->count ?? 0), (int) ($core->count ?? 0), $core->example ?? null, $standard->example ?? null);
        }

        return $populations;
    }
}

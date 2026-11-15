<?php

namespace App\Console\Commands;

use App\AnthropicBudget\Account;
use App\AnthropicBudget\Planner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class PlanAnthropicBudget extends Command
{
    protected $signature = 'ekko:anthropic-budget';

    protected $description = 'Forecast the Anthropic billing cycle and allocate daily additions';

    public function handle(Account $account, Planner $planner): int
    {
        if (! $account->configured()) {
            $this->components->warn(__('anthropic_budget.unconfigured'));

            return self::FAILURE;
        }
        $planner->plan(CarbonImmutable::now('UTC'));
        $this->components->info(__('anthropic_budget.planned'));

        return self::SUCCESS;
    }
}

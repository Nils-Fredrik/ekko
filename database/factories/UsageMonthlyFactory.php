<?php

namespace Database\Factories;

use App\Models\UsageMonthly;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UsageMonthly> */
class UsageMonthlyFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'month' => today()->startOfMonth(),
            'calls' => 1,
            'cost_usd' => '0.00100000',
            'quota_calls' => 4000,
            'budget_usd' => '50.00',
            'status' => 'ok',
        ];
    }
}

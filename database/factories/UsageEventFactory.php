<?php

namespace Database\Factories;

use App\Models\Run;
use App\Models\UsageEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UsageEvent> */
class UsageEventFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'occurred_at' => now(),
            'kind' => 'engine_call',
            'run_id' => Run::factory(),
            'engine_key' => fn (array $attributes): string => Run::query()->findOrFail($attributes['run_id'])->engine_key,
            'calls' => 1,
            'input_tokens' => 250,
            'output_tokens' => 100,
            'search_calls' => 1,
            'cost_usd' => '0.00100000',
            'billed_to' => 'platform',
        ];
    }
}

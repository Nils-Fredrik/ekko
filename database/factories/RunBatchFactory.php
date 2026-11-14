<?php

namespace Database\Factories;

use App\Models\Market;
use App\Models\RunBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RunBatch> */
class RunBatchFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'market_id' => Market::factory(),
            'scheduled_for' => now(),
            'status' => 'queued',
            'planned_runs' => 1,
            'completed_runs' => 0,
            'failed_runs' => 0,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\ExtractionJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExtractionJob> */
class ExtractionJobFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'provider_batch_id' => $this->faker->uuid(),
            'status' => 'submitted',
            'response_ids' => [],
            'submitted_at' => now(),
        ];
    }
}

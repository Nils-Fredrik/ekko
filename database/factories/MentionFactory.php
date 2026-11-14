<?php

namespace Database\Factories;

use App\Models\Mention;
use App\Models\Response;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Mention> */
class MentionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'response_id' => Response::factory(),
            'entity_type' => 'unknown',
            'entity_id' => null,
            'surface_name' => $this->faker->company(),
            'detection' => 'llm',
            'is_recommended' => false,
        ];
    }
}

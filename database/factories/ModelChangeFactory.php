<?php

namespace Database\Factories;

use App\Models\Engine;
use App\Models\ModelChange;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ModelChange> */
class ModelChangeFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'engine_key' => Engine::factory(),
            'changed_on' => today(),
            'from_model' => 'model-a',
            'to_model' => 'model-b',
            'changed_at' => now(),
        ];
    }
}

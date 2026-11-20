<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\BrandPlanEngine;
use App\Models\Engine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BrandPlanEngine> */
class BrandPlanEngineFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory(),
            'engine_key' => Engine::factory(),
            'frequency' => 'daily',
            'prompt_scope' => 'all',
            'repetitions' => 1,
        ];
    }
}

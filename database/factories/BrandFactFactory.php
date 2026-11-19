<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\BrandFact;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BrandFact> */
class BrandFactFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory(),
            'text' => 'Founded in '.$this->faker->numberBetween(1950, 2015),
            'is_active' => true,
        ];
    }
}

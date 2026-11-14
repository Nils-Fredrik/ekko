<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\BrandAlias;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BrandAlias> */
class BrandAliasFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory(),
            'alias' => $this->faker->unique()->word(),
            'match_mode' => 'case_insensitive',
        ];
    }
}

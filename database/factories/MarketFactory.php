<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Market;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Market> */
class MarketFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory(),
            'country' => 'NO',
            'language' => 'nb',
            'location_label' => null,
            'is_active' => true,
        ];
    }
}

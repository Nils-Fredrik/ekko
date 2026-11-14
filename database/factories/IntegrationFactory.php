<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Integration;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Integration> */
class IntegrationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory(),
            'type' => 'ga4',
            'config' => ['property_id' => '123456789'],
            'status' => 'pending',
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\AlertRule;
use App\Models\Brand;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AlertRule> */
class AlertRuleFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory(),
            'type' => 'visibility_drop',
            'threshold' => '10.0000',
            'channels' => ['in_app'],
            'is_active' => true,
        ];
    }
}

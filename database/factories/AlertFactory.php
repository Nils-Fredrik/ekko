<?php

namespace Database\Factories;

use App\Models\Alert;
use App\Models\AlertRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Alert> */
class AlertFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'alert_rule_id' => AlertRule::factory(),
            'triggered_at' => now(),
            'payload' => ['change_percentage_points' => -12],
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\AiReferralDaily;
use App\Models\Brand;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AiReferralDaily> */
class AiReferralDailyFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory(),
            'source' => 'chatgpt.com',
            'date' => today(),
            'sessions' => 10,
            'engaged_sessions' => 6,
            'conversions' => '1.0000',
        ];
    }
}

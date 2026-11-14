<?php

namespace Database\Factories;

use App\Models\BotHitDaily;
use App\Models\Brand;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BotHitDaily> */
class BotHitDailyFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory(),
            'domain' => fn (array $attributes): string => Brand::query()->findOrFail($attributes['brand_id'])->primary_domain,
            'bot' => 'GPTBot',
            'date' => today(),
            'hits' => 8,
            'unique_paths' => 2,
            'top_paths' => [
                ['path' => '/', 'hits' => 5],
                ['path' => '/guide', 'hits' => 3],
            ],
        ];
    }
}

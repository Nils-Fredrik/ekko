<?php

namespace Database\Factories;

use App\Models\Engine;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Engine> */
class EngineFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'key' => 'demo_'.Str::lower(Str::random(12)),
            'family' => 'chatgpt',
            'method' => 'api',
            'provider' => 'openai',
            'model_id' => null,
            'config' => [],
            'is_enabled' => true,
        ];
    }
}

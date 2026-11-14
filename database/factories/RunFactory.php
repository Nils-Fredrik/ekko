<?php

namespace Database\Factories;

use App\Models\Engine;
use App\Models\Prompt;
use App\Models\Run;
use App\Models\RunBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Run> */
class RunFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'run_batch_id' => RunBatch::factory(),
            'prompt_id' => fn (array $attributes): int => Prompt::factory()->create([
                'market_id' => RunBatch::query()->findOrFail($attributes['run_batch_id'])->market_id,
            ])->id,
            'engine_key' => Engine::factory(),
            'repetition' => 1,
            'status' => 'queued',
            'attempts' => 0,
        ];
    }
}

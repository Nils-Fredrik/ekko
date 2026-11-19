<?php

namespace Database\Factories;

use App\Enums\ActionStatus;
use App\Enums\ActionType;
use App\Models\Action;
use App\Models\Brand;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Action> */
class ActionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory(),
            'type' => ActionType::SourceGap,
            'title' => $this->faker->sentence(4),
            'description' => $this->faker->sentence(12),
            'steps' => [['text' => $this->faker->sentence(6), 'done' => false]],
            'evidence' => [],
            'competitor_evidence' => [],
            'engine_keys' => [],
            'impact_level' => 3,
            'impact_score' => 10,
            'status' => ActionStatus::New,
            'created_by' => Action::CREATED_BY_SYSTEM,
            'text_source' => 'template',
            'locale' => 'nb',
            'fingerprint' => hash('sha256', $this->faker->unique()->uuid()),
        ];
    }

    public function own(): static
    {
        return $this->state(['created_by' => Action::CREATED_BY_USER, 'fingerprint' => null, 'text_source' => 'user', 'status' => ActionStatus::Planned]);
    }
}

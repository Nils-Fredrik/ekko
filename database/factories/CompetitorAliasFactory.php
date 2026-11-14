<?php

namespace Database\Factories;

use App\Models\Competitor;
use App\Models\CompetitorAlias;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CompetitorAlias> */
class CompetitorAliasFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'competitor_id' => Competitor::factory(),
            'alias' => $this->faker->unique()->word(),
            'match_mode' => 'exact_word',
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Competitor;
use App\Models\CompetitorDomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CompetitorDomain> */
class CompetitorDomainFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'competitor_id' => Competitor::factory(),
            'domain' => $this->faker->unique()->domainWord().'.example.test',
        ];
    }
}

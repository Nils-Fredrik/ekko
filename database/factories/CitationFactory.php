<?php

namespace Database\Factories;

use App\Models\Citation;
use App\Models\Response;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Citation> */
class CitationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $domain = $this->faker->domainWord().'.example.test';
        $url = 'https://'.$domain.'/guide';

        return [
            'response_id' => Response::factory(),
            'url' => $url,
            'url_normalized' => $url,
            'domain' => $domain,
            'ownership' => 'third_party',
            'source_type' => 'review',
            'rank_in_answer' => 1,
            'from_annotation' => true,
        ];
    }
}

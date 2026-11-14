<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\BrandDomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BrandDomain> */
class BrandDomainFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory(),
            'domain' => $this->faker->unique()->domainWord().'.example.test',
            'include_subdomains' => false,
        ];
    }
}

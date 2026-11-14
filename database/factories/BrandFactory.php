<?php

namespace Database\Factories;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Brand> */
class BrandFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = $this->faker->unique()->company();
        $slug = Str::slug($name);

        return [
            'name' => $name,
            'slug' => $slug,
            'primary_domain' => $slug.'.example.test',
            'status' => 'active',
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\ReportSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReportSchedule> */
class ReportScheduleFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory(),
            'cadence' => 'weekly',
            'recipients' => ['reports@example.test'],
            'locale' => 'nb',
            'branding' => 'tenant',
        ];
    }
}

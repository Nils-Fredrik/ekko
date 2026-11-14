<?php

namespace Database\Factories;

use App\Models\Report;
use App\Models\ReportSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Report> */
class ReportFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $lastWeek = today()->subWeek();

        return [
            'report_schedule_id' => ReportSchedule::factory(),
            'period_start' => $lastWeek->copy()->startOfWeek(),
            'period_end' => $lastWeek->endOfWeek(),
        ];
    }
}

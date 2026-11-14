<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Report extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ReportFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'report_schedule_id',
        'period_start',
        'period_end',
        'pdf_uri',
        'sent_at',
    ];

    /** @return BelongsTo<ReportSchedule, $this> */
    public function reportSchedule(): BelongsTo
    {
        return $this->belongsTo(ReportSchedule::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'sent_at' => 'datetime',
        ];
    }
}

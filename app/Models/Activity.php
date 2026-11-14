<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ActivityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

class Activity extends SpatieActivity
{
    use BelongsToTenant;

    /** @use HasFactory<ActivityFactory> */
    use HasFactory;

    public $guarded = ['tenant_id'];

    /** @var list<string> */
    protected $fillable = [
        'log_name',
        'description',
        'subject_type',
        'subject_id',
        'event',
        'causer_type',
        'causer_id',
        'attribute_changes',
        'properties',
    ];
}

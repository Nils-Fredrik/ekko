<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Observers\AnthropicSpendObserver;
use App\Observers\UsageWorkObserver;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Factories\UsageEventFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property string $cost_usd Exact decimal:8 cast, never a binary float. */
#[ObservedBy([AnthropicSpendObserver::class, UsageWorkObserver::class])]
class UsageEvent extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<UsageEventFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (UsageEvent $event): void {
            $team = Team::query()->findOrFail(app(TenantContext::class)->require());
            $projectKind = $team->kind;
            if ($projectKind !== 'pitch' && $team->pitch_converted_at !== null) {
                // A collected run can be booked after conversion; use its creation time to retain provenance.
                $started = $event->run_id === null ? $event->occurred_at : Run::query()->whereKey($event->run_id)->value('created_at');
                if ($started !== null && CarbonImmutable::parse($started)->lessThan($team->pitch_converted_at)) {
                    $projectKind = 'pitch';
                }
            }
            $event->setAttribute('project_kind', $projectKind);
        });
    }

    /** @var list<string> */
    protected $fillable = [
        'collection_attempt_token',
        'anthropic_operation',
        'occurred_at',
        'kind',
        'engine_key',
        'calls',
        'input_tokens',
        'output_tokens',
        'search_calls',
        'cost_usd',
        'billed_to',
        'run_id',
    ];

    /** @return BelongsTo<Run, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    /** @return BelongsTo<Engine, $this> */
    public function engine(): BelongsTo
    {
        return $this->belongsTo(Engine::class, 'engine_key');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'cost_usd' => 'decimal:8',
        ];
    }
}

<?php

namespace App\Pitch;

use App\Enums\TenantStatus;
use App\Models\Plan;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\TenantAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Pitch creation is serialized on the partner row, so concurrent requests cannot exceed the quota. */
final class PitchProjects
{
    public const DAYS = 7;

    public const PARTNER_LIMIT = 3;

    public function __construct(private readonly TenantAccess $access) {}

    public function create(User $actor, Team $partner, string $name, Plan $plan, CarbonImmutable $at): Team
    {
        return DB::transaction(function () use ($actor, $partner, $name, $plan, $at): Team {
            $partner = Team::query()->lockForUpdate()->findOrFail($partner->id);
            abort_unless($actor->is_platform_admin || $this->access->advisesPartner($actor, $partner), 403);
            abort_if($partner->personal_team || $partner->partner_team_id !== null || $partner->status !== TenantStatus::Active, 422);
            abort_unless($actor->is_platform_admin || $partner->plan_id === $plan->id, 403);
            $count = Team::query()->where('partner_team_id', $partner->id)->where('kind', 'pitch')
                ->where('status', TenantStatus::Active->value)->where('pitch_expires_at', '>', $at)->count();
            if ($count >= self::PARTNER_LIMIT) {
                throw ValidationException::withMessages(['pitch' => __('pitch.limit')]);
            }
            $name = trim($name);
            if ($name === '') {
                throw ValidationException::withMessages(['name' => __('pitch.name_required')]);
            }
            $team = new Team(['name' => $name, 'personal_team' => false]);
            $team->forceFill([
                'user_id' => $partner->user_id,
                'partner_team_id' => $partner->id,
                'plan_id' => $plan->id,
                'kind' => 'pitch',
                'pitch_expires_at' => $at->addDays(self::DAYS),
                'default_locale' => $partner->default_locale,
                'timezone' => $partner->timezone,
            ])->save();

            return $team;
        });
    }

    public function convert(User $actor, Team $pitch, CarbonImmutable $at): Team
    {
        return DB::transaction(function () use ($actor, $pitch, $at): Team {
            $pitch = Team::query()->lockForUpdate()->findOrFail($pitch->id);
            $partner = $pitch->partner_team_id === null ? null : Team::query()->find($pitch->partner_team_id);
            abort_unless($actor->is_platform_admin || ($partner !== null && $this->access->advisesPartner($actor, $partner)), 403);
            abort_unless($pitch->kind === 'pitch' && $pitch->status !== TenantStatus::Closed, 422);
            // Only metadata changes. Brand, market, prompts, runs, reports and usage retain the same tenant ID.
            $pitch->forceFill(['kind' => 'customer', 'pitch_converted_at' => $at])->save();

            return $pitch;
        });
    }
}

<?php

namespace Database\Factories;

use App\Enums\TenantStatus;
use App\Models\Plan;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->company(),
            'user_id' => User::factory(),
            'personal_team' => true,
        ];
    }

    /**
     * A tenant that is not a Jetstream personal team.
     */
    public function tenant(): static
    {
        return $this->state(['personal_team' => false]);
    }

    /**
     * A client tenant managed by the given partner tenant.
     */
    public function clientOf(Team $partner): static
    {
        return $this->tenant()->state(['partner_team_id' => $partner->id]);
    }

    public function withPlan(?Plan $plan = null): static
    {
        return $this->state(['plan_id' => $plan ?? Plan::factory()]);
    }

    public function status(TenantStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}

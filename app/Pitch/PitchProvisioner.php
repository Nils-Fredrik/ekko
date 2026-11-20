<?php

namespace App\Pitch;

use App\Models\Brand;
use App\Models\Team;
use App\Models\User;
use App\Onboarding\Fetch\SiteUrl;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantAccess;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Creates the project and its domain; prompt and engine setup remains explicit, with no paid calls here. */
final class PitchProvisioner
{
    public function authorize(User $actor, Team $partner): void
    {
        abort_unless($actor->is_platform_admin || app(TenantAccess::class)->advisesPartner($actor, $partner), 403);
        abort_if($partner->personal_team || $partner->partner_team_id !== null, 403);
    }

    /** @param array<string, mixed> $input */
    public function create(User $actor, Team $partner, array $input): Team
    {
        $this->authorize($actor, $partner);
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:120'],
            'domain' => ['required', 'string', 'max:255'],
            'country' => ['required', 'string', 'regex:/^[a-zA-Z]{2}$/'],
            'language' => ['required', 'string', 'max:35', 'regex:/^[a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{2,8})*$/'],
            'partner_team_id' => ['prohibited'], 'tenant_id' => ['prohibited'],
        ])->validate();
        $domain = SiteUrl::hostFromUserInput($data['domain']);
        if ($domain === null) {
            throw ValidationException::withMessages(['domain' => __('onboarding.invalid_domain')]);
        }
        $language = explode('-', $data['language']);
        $language[0] = strtolower($language[0]);
        $data['language'] = implode('-', $language);

        return DB::transaction(function () use ($actor, $partner, $data, $domain): Team {
            $pitch = app(PitchProjects::class)->create($actor, $partner, $data['name'], CarbonImmutable::now('UTC'));
            // Only this newly created, authorized tenant is added for these writes; restore the caller's RLS afterwards.
            app(TenantContext::class)->run($pitch->id, fn () => app(TenantRls::class)->run([$pitch->id], function () use ($data, $domain): void {
                $brand = Brand::query()->create(['name' => $data['name'], 'slug' => Str::slug($data['name']) ?: 'pitch', 'primary_domain' => $domain, 'status' => 'active']);
                $brand->domains()->create(['domain' => $domain, 'include_subdomains' => true]);
                $brand->aliases()->create(['alias' => $data['name'], 'match_mode' => 'case_insensitive']);
                $brand->markets()->create(['country' => strtoupper($data['country']), 'language' => $data['language'], 'is_active' => true]);
            }));

            return $pitch;
        });
    }
}

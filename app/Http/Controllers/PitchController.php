<?php

namespace App\Http\Controllers;

use App\Enums\TenantStatus;
use App\Models\Brand;
use App\Models\Response as Answer;
use App\Models\Run;
use App\Models\Team;
use App\Models\User;
use App\Pitch\PitchProjects;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantAccess;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pitch projects of the partner the advisor is signed into (docs/spec.md 11.5, T47, T49): create, follow and convert.
 * The figures of each pitch are read inside that pitch's row-level security, never from another partner's pitches.
 */
final class PitchController extends Controller
{
    public function index(Request $request, TenantAccess $access, TenantContext $context, TenantRls $rls): Response
    {
        $actor = $request->user();
        $partner = $actor?->currentTeam;
        abort_unless($actor instanceof User && $partner instanceof Team && $access->advisesPartner($actor, $partner), 403);
        $now = CarbonImmutable::now('UTC');

        $pitches = Team::query()->where('partner_team_id', $partner->id)
            ->where('status', '!=', TenantStatus::Closed->value)
            ->where(fn ($query) => $query->where('kind', 'pitch')->orWhereNotNull('pitch_converted_at'))
            ->orderByDesc('created_at')->orderByDesc('id')->get()
            ->map(fn (Team $pitch): array => [
                'id' => $pitch->id,
                'name' => $pitch->name,
                'status' => $this->status($pitch, $now),
                'created_at' => $pitch->created_at?->toIso8601String(),
                'expires_at' => $pitch->pitch_expires_at?->toIso8601String(),
                'converted_at' => $pitch->pitch_converted_at?->toIso8601String(),
                'day' => $this->day($pitch, $now),
                'days' => PitchProjects::DAYS,
                ...$context->run($pitch->id, fn (): array => $rls->run([$pitch->id], fn (): array => $this->progress())),
            ])
            // Running pitches first, then finished, then converted; the newest first within each.
            ->sortBy(fn (array $pitch): int => array_search($pitch['status'], ['active', 'expired', 'converted'], true))
            ->values()->all();

        return Inertia::render('Pitch/Index', [
            'pitches' => $pitches,
            'limit' => PitchProjects::PARTNER_LIMIT,
            'active' => count(array_filter($pitches, fn (array $pitch): bool => $pitch['status'] === 'active')),
        ]);
    }

    /**
     * Opens a pitch project: the advisor switches to it and lands on the wizard until the brand is set up, then on
     * the pitch report. Switching goes through the same rule as the tenant switcher (TenantAccess::canSelect).
     */
    public function open(Request $request, Team $team, TenantAccess $access, TenantContext $context, TenantRls $rls): RedirectResponse
    {
        $actor = $request->user();
        $partner = $team->partner_team_id === null ? null : Team::query()->find($team->partner_team_id);
        abort_unless($actor instanceof User && $partner instanceof Team && $access->advisesPartner($actor, $partner), 403);
        abort_unless($team->kind === 'pitch' || $team->pitch_converted_at !== null, 404);
        abort_unless($actor->switchTeam($team), 403);
        $brandId = $context->run($team->id, fn () => $rls->run([$team->id], fn () => Brand::query()->orderBy('id')->value('id')));

        return $brandId === null
            ? redirect()->route('onboarding.wizard')
            : redirect()->route('pitches.report', $brandId);
    }

    public function store(Request $request, PitchProjects $projects, TenantAccess $access): RedirectResponse
    {
        $actor = $request->user();
        $partner = $actor?->currentTeam;
        abort_unless($actor instanceof User && $partner instanceof Team && $access->advisesPartner($actor, $partner), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $projects->create($actor, $partner, $data['name'], CarbonImmutable::now('UTC'));

        return back(303);
    }

    public function adminStore(Request $request, PitchProjects $projects): RedirectResponse
    {
        $actor = $request->user();
        Gate::allowIf($actor?->is_platform_admin === true);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'partner_team_id' => ['required', 'integer', 'exists:teams,id'],
        ]);
        $projects->create($actor, Team::query()->findOrFail($data['partner_team_id']), $data['name'], CarbonImmutable::now('UTC'));

        return back(303);
    }

    public function convert(Request $request, Team $team, PitchProjects $projects): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $projects->convert($actor, $team, CarbonImmutable::now('UTC'));

        return back(303);
    }

    private function status(Team $pitch, CarbonImmutable $now): string
    {
        if ($pitch->pitch_converted_at !== null) {
            return 'converted';
        }

        return $pitch->mayCollectAt($now) ? 'active' : 'expired';
    }

    /** The day of the seven the pitch is on, counted from creation and capped at the last day. */
    private function day(Team $pitch, CarbonImmutable $now): int
    {
        $start = CarbonImmutable::parse($pitch->created_at)->utc();
        $end = $pitch->pitch_expires_at === null ? $now : $now->min($pitch->pitch_expires_at);

        return max(1, min(PitchProjects::DAYS, (int) floor($start->diffInDays($end)) + 1));
    }

    /** @return array{brand: array{id: int, name: string}|null, prompts: int, runs: int, answers: int, last_answer_at: string|null} */
    private function progress(): array
    {
        $brand = Brand::query()->orderBy('id')->first(['id', 'name']);
        $last = Answer::query()->max('collected_at');

        return [
            'brand' => $brand === null ? null : ['id' => $brand->id, 'name' => $brand->name],
            'prompts' => $brand === null ? 0 : $brand->markets()->withCount('prompts')->get()->sum('prompts_count'),
            'runs' => Run::query()->count(),
            'answers' => Answer::query()->count(),
            'last_answer_at' => $last === null ? null : CarbonImmutable::parse($last)->utc()->toIso8601String(),
        ];
    }
}

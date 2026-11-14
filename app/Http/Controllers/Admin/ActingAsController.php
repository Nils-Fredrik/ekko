<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\ActingAs;
use App\Tenancy\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * "Act as tenant" (docs/spec.md 5.2 and 16): a platform admin sees a tenant as it sees itself.
 * Starting needs a reason and a recently confirmed password, and both start and stop are logged.
 */
class ActingAsController extends Controller
{
    public function __construct(
        private readonly ActingAs $actingAs,
        private readonly AuditLog $audit,
    ) {}

    public function store(Request $request, Team $team): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        Gate::authorize('actAs', $team);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $this->stopCurrent($admin);
        $this->actingAs->start($admin, $team, $validated['reason']);
        $this->audit->actingAsStarted($admin, $team, $validated['reason']);

        return redirect()->route('dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $this->stopCurrent($admin);

        return redirect()->route('dashboard');
    }

    private function stopCurrent(User $admin): void
    {
        $state = $this->actingAs->state();

        if ($state !== null && $state['admin_id'] === $admin->id) {
            $tenant = Team::query()->find($state['tenant_id']);
            if ($tenant !== null) {
                $this->audit->actingAsStopped($admin, $tenant);
            }
        }

        $this->actingAs->stop();
    }
}

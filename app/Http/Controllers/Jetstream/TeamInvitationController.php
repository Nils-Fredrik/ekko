<?php

namespace App\Http\Controllers\Jetstream;

use App\Models\TeamInvitation;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Jetstream\Http\Controllers\TeamInvitationController as JetstreamTeamInvitationController;

/**
 * Jetstream's invitation controller. Jetstream adds the invited e-mail address on behalf of the
 * team owner, whoever opens the signed link. Here only the invited user can accept it.
 */
class TeamInvitationController extends JetstreamTeamInvitationController
{
    /**
     * @param  int|string  $invitationId
     */
    public function accept(Request $request, $invitationId): RedirectResponse
    {
        $invitation = TeamInvitation::query()->whereKey($invitationId)->firstOrFail();

        $user = $request->user();

        abort_unless(strcasecmp((string) $user?->email, $invitation->email) === 0, 403);

        // The invitation proves the e-mail address only if the user has proven it too (finding #140,
        // ADR 0006): changing the e-mail address on a profile clears the verification.
        abort_unless($user instanceof MustVerifyEmail && $user->hasVerifiedEmail(), 403, __('auth.invitation.unverified'));

        // The server is the judge: an old signed link stops working after 7 days (docs/spec.md 5.4).
        if ($invitation->isExpired()) {
            $invitation->delete();

            return redirect(config('fortify.home'))->dangerBanner(__('auth.invitation.expired'));
        }

        $response = parent::accept($request, $invitationId);

        // An invited user without a current tenant lands in the tenant just joined, not nowhere.
        if ($user->current_team_id === null) {
            $user->switchTeam($invitation->team);
        }

        return $response;
    }
}

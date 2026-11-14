<?php

namespace App\Http\Controllers\Jetstream;

use App\Models\TeamInvitation;
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

        abort_unless(strcasecmp((string) $request->user()?->email, $invitation->email) === 0, 403);

        return parent::accept($request, $invitationId);
    }
}

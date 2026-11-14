<?php

namespace App\Http\Controllers\Jetstream;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Jetstream\Http\Controllers\Inertia\ApiTokenController as JetstreamApiTokenController;

/**
 * Jetstream's API token controller. Jetstream's destroy() calls first()->delete(), so another
 * user's token id gave a 500; here it gives 404, like update().
 */
class ApiTokenController extends JetstreamApiTokenController
{
    /**
     * @param  string  $tokenId
     */
    public function destroy(Request $request, $tokenId): RedirectResponse
    {
        $request->user()->tokens()->where('id', $tokenId)->firstOrFail()->delete();

        return back(303);
    }
}

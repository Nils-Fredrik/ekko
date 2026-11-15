<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Acknowledging an alert (docs/spec.md 11.1): the receipt that says a person has seen it and is dealing with it.
 */
class AlertController extends Controller
{
    public function acknowledge(Alert $alert): RedirectResponse
    {
        Gate::authorize('update', $alert);

        // The first receipt stands: acknowledging again does not change who or when.
        if ($alert->acknowledged_at === null) {
            $alert->forceFill([
                'acknowledged_by' => request()->user()?->id,
                'acknowledged_at' => now(),
            ])->save();
        }

        return back();
    }
}

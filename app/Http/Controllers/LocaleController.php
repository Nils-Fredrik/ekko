<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Switch language. Stored on the signed-in user, and in the session so the login page
 * can switch before anyone is signed in (docs/spec.md 14.1).
 */
class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', Rule::in(config('ekko.locales'))],
        ]);

        $request->session()->put('locale', $data['locale']);

        $user = $request->user();
        if ($user instanceof User) {
            $user->forceFill(['locale' => $data['locale']])->save();
        }

        return back();
    }
}

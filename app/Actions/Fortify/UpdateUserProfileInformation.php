<?php

namespace App\Actions\Fortify;

use App\Mail\EmailAddressChangedMail;
use App\Models\User;
use App\Rules\UniqueEmail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * Validate and update the given user's profile information.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(User $user, array $input): void
    {
        $changesEmail = is_string($input['email'] ?? null) && Str::lower(trim($input['email'])) !== $user->email;

        // A new address can take over the account through verification and password reset, so changing it
        // needs the password, and a hijacked session alone is not enough (T39-F4).
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', new UniqueEmail($user->id)],
            'photo' => ['nullable', 'mimes:jpg,jpeg,png', 'max:1024'],
            ...($changesEmail ? ['current_password' => ['required', 'string', 'current_password:web']] : []),
        ])->validateWithBag('updateProfileInformation');

        if (isset($input['photo'])) {
            $user->updateProfilePhoto($input['photo']);
        }

        // User is MustVerifyEmail: a changed address must be verified again (ADR 0008, finding #140).
        if ($changesEmail) {
            $this->updateVerifiedUser($user, $input);
        } else {
            $user->forceFill([
                'name' => $input['name'],
                'email' => $input['email'],
            ])->save();
        }
    }

    /**
     * Update the given verified user's profile information.
     *
     * @param  array<string, string>  $input
     */
    protected function updateVerifiedUser(User $user, array $input): void
    {
        $oldEmail = $user->email;

        $user->forceFill([
            'name' => $input['name'],
            'email' => $input['email'],
            'email_verified_at' => null,
        ])->save();

        $user->sendEmailVerificationNotification();

        // The old address learns about the change, so its owner can react to a change they did not make.
        Mail::to($oldEmail)->send(new EmailAddressChangedMail($user->name, $user->email));
    }
}

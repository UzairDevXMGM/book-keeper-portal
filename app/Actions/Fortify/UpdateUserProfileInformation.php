<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;
use Throwable;

class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * Validate and update the given user's profile information.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        $input['email'] = Str::lower(trim((string) ($input['email'] ?? '')));
        $emailChanged = $input['email'] !== $user->email;

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],

            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users')->ignore($user->id),
            ],
        ])->validateWithBag('updateProfileInformation');

        if ($input['email'] !== $user->email &&
            $user instanceof MustVerifyEmail) {
            $this->updateVerifiedUser($user, $input);
        } else {
            $user->forceFill([
                'name' => $input['name'],
                'email' => $input['email'],
            ])->save();
        }

        if ($user->wasChanged(['name', 'email'])) {
            Audit::log('profile.updated', $user, array_keys(array_diff_key($user->getChanges(), ['updated_at' => 1])), user: $user);
        }

        // Stripe receipts and invoices go to the owner's email
        $company = $user->company;
        if ($emailChanged && $company?->hasStripeId()) {
            try {
                $company->updateStripeCustomer(['email' => $user->email]);
            } catch (Throwable $e) {
                Log::warning('Could not update Stripe customer email', ['company_id' => $company->id, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Update the given verified user's profile information.
     *
     * @param  array<string, string>  $input
     */
    protected function updateVerifiedUser(User $user, array $input): void
    {
        $user->forceFill([
            'name' => $input['name'],
            'email' => $input['email'],
            'email_verified_at' => null,
        ])->save();

        $user->sendEmailVerificationNotification();
    }
}

<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\CompanyProvisioner;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    public function __construct(private CompanyProvisioner $provisioner)
    {
    }

    /**
     * Validate and create a newly registered user together with their business and its chart of accounts.
     * Payment happens next, on Stripe Checkout (see RegisterResponse).
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        if (! Settings::get('registration_open')) {
            throw ValidationException::withMessages(['email' => 'New sign-ups are paused right now. Please try again later.']);
        }

        // Emails are stored lower-case so login (which lower-cases what is typed) always finds them
        $input['email'] = Str::lower(trim((string) ($input['email'] ?? '')));

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'password' => $this->passwordRules(),
            'company_name' => ['required', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+().\-\s]{7,30}$/'],
            'business_type' => ['required', Rule::in(array_keys(config('business.types')))],
            'industry' => ['required', Rule::in(array_merge(...array_values(config('business.industries'))))],
            'state' => ['required', Rule::in(array_keys(config('business.states')))],
            'fiscal_year_start_month' => ['required', 'integer', 'between:1,12'],
            'terms' => ['accepted'],
        ], [
            'terms.accepted' => 'Please accept the Terms of Service and Privacy Policy.',
            'phone.regex' => 'Please enter a valid phone number.',
        ], [
            'company_name' => 'business name',
            'business_type' => 'business type',
            'fiscal_year_start_month' => 'fiscal year start',
        ])->validate();

        return DB::transaction(function () use ($input) {
            // role / is_active are not mass assignable and keep their defaults (customer, active)
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => Hash::make($input['password']),
            ]);

            $this->provisioner->create($user, [
                'name' => $input['company_name'],
                'phone' => $input['phone'] ?? null,
                'business_type' => $input['business_type'],
                'industry' => $input['industry'],
                'state' => $input['state'],
                'fiscal_year_start_month' => (int) $input['fiscal_year_start_month'],
            ]);

            return $user;
        });
    }
}

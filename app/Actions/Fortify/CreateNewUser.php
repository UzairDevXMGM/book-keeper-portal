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

/**
 * Sign-up rules and account creation. The sign-up page (SignupController) checks the details first, takes the
 * payment, and only then creates the user and the business, so nobody ends up with an account they never paid for.
 */
class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    public function __construct(private CompanyProvisioner $provisioner)
    {
    }

    /**
     * Checks the sign-up details and returns them cleaned up.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(array $input): array
    {
        if (! Settings::get('registration_open')) {
            throw ValidationException::withMessages(['email' => 'New sign-ups are paused right now. Please try again later.']);
        }

        // Emails are stored lower-case so login (which lower-cases what is typed) always finds them
        $input['email'] = Str::lower(trim((string) ($input['email'] ?? '')));

        return Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
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
            'email.unique' => 'An account with this email already exists. Please log in instead.',
        ], [
            'company_name' => 'business name',
            'business_type' => 'business type',
            'fiscal_year_start_month' => 'fiscal year start',
        ])->validate();
    }

    /**
     * Fortify's interface: checks the details and creates the account straight away (used by tests and tools only;
     * the sign-up page goes through validate() + createFromValidated() around the payment).
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $data = $this->validate($input);

        return $this->createFromValidated($data + ['password_hash' => Hash::make($data['password'])]);
    }

    /**
     * Creates the user, the business and its chart of accounts from details that already passed validate().
     * Takes the password as a hash, so the plain password never has to be kept between requests.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $company  extra business fields (Stripe customer, card, city)
     */
    public function createFromValidated(array $data, array $company = []): User
    {
        return DB::transaction(function () use ($data, $company) {
            // role / is_active are not mass assignable and keep their defaults (customer, active)
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password_hash'],
            ]);

            $this->provisioner->create($user, [
                'name' => $data['company_name'],
                'phone' => $data['phone'] ?? null,
                'business_type' => $data['business_type'],
                'industry' => $data['industry'],
                'state' => $data['state'],
                'fiscal_year_start_month' => (int) $data['fiscal_year_start_month'],
            ] + $company);

            return $user;
        });
    }
}

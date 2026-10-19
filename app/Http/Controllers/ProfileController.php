<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use App\Services\Reports\ReportAccess;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * "My profile": personal details and password (saved by Fortify's routes), business details and the plan summary.
 */
class ProfileController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();
        $company = $user->company;

        return view('profile.show', [
            'user' => $user,
            'company' => $company,
            'plan' => $company ? ReportAccess::planFor($company) : null,
            'subscription' => $company?->subscription(Company::SUBSCRIPTION),
            'addons' => $company ? $company->addons()->where('status', 'active')->with('report')->latest()->get() : collect(),
        ]);
    }

    public function updateBusiness(Request $request): RedirectResponse
    {
        $company = $this->company($request);

        $data = $request->validateWithBag('updateBusiness', [
            'name' => ['required', 'string', 'max:200'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+().\-\s]{7,30}$/'],
            'business_type' => ['required', Rule::in(array_keys(config('business.types')))],
            'industry' => ['required', Rule::in(array_merge(...array_values(config('business.industries'))))],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['required', Rule::in(array_keys(config('business.states')))],
            'fiscal_year_start_month' => ['required', 'integer', 'between:1,12'],
            'accounting_method' => ['required', 'in:cash,accrual'],
        ], [
            'phone.regex' => 'Please enter a valid phone number.',
        ], [
            'name' => 'business name',
            'fiscal_year_start_month' => 'fiscal year start',
        ]);

        $company->update($data);
        if ($company->wasChanged()) {
            Audit::log('business.updated', $company, array_keys(array_diff_key($company->getChanges(), ['updated_at' => 1])));
        }

        // Keep the name and phone on Stripe invoices current. Only these fields are sent, so the billing address the
        // customer entered at checkout is left alone.
        if ($company->hasStripeId() && $company->wasChanged(['name', 'phone'])) {
            try {
                $company->updateStripeCustomer(['name' => $company->name, 'phone' => $company->phone ?? '']);
            } catch (Throwable $e) {
                Log::warning('Could not update Stripe customer', ['company_id' => $company->id, 'error' => $e->getMessage()]);
            }
        }

        return redirect()->to(route('profile.show').'#business')->with('status', 'Business details saved.');
    }

    /** Uploads a new profile picture (JPG, PNG or WebP up to 2 MB) and removes the old one. */
    public function updatePhoto(Request $request): RedirectResponse
    {
        $request->validateWithBag('updatePhoto', [
            'photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=40,min_height=40,max_width=6000,max_height=6000'],
        ], [
            'photo.required' => 'Choose a picture first.',
            'photo.image' => 'The picture must be a JPG, PNG or WebP image.',
            'photo.mimes' => 'The picture must be a JPG, PNG or WebP image.',
            'photo.max' => 'The picture must be 2 MB or smaller.',
            'photo.dimensions' => 'The picture must be at least 40 × 40 pixels.',
        ]);

        $user = $request->user();
        $old = $user->avatar_path;
        $path = $request->file('photo')->store('avatars', 'local');
        $user->forceFill(['avatar_path' => $path])->save();
        if ($old && $old !== $path) {
            Storage::disk('local')->delete($old);
        }
        Audit::log('profile.photo_updated', $user);

        return redirect()->route('profile.show')->with('status', 'Your profile picture was updated.');
    }

    public function destroyPhoto(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->avatar_path) {
            Storage::disk('local')->delete($user->avatar_path);
            $user->forceFill(['avatar_path' => null])->save();
            Audit::log('profile.photo_removed', $user);
        }

        return redirect()->route('profile.show')->with('status', 'Your profile picture was removed.');
    }

    /** The picture itself. Kept outside the public folder; only its owner and the platform admin can open it. */
    public function photo(Request $request, User $user): StreamedResponse
    {
        abort_unless($request->user()->is($user) || $request->user()->isAdmin(), 404);
        abort_unless($user->avatar_path && Storage::disk('local')->exists($user->avatar_path), 404);

        return Storage::disk('local')->response($user->avatar_path, null, ['Cache-Control' => 'private, max-age=604800']);
    }
}

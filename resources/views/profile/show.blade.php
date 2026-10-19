@extends('layouts.app')

@section('title', 'My profile')

@section('content')
    @php
        // Both forms have a "name" field, so a form only refills old input when it was the one that failed validation
        $personalOld = fn (string $key, $default) => $errors->updateProfileInformation->any() ? old($key, $default) : $default;
        $businessOld = fn (string $key, $default) => $errors->updateBusiness->any() ? old($key, $default) : $default;
    @endphp

    <div class="profile-hero card mb-4">
        <div class="card-body d-flex flex-wrap align-items-center gap-3">
            {{-- Profile picture: choosing a file uploads it straight away --}}
            <div class="avatar-editor">
                <x-avatar :user="$user" size="xl" />
                <div class="avatar-actions">
                    <form method="POST" action="{{ route('profile.photo.update') }}" enctype="multipart/form-data">
                        @csrf
                        <label for="photo">{{ $user->avatar_path ? 'Change photo' : 'Add photo' }}</label>
                        <input id="photo" type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="visually-hidden" data-autosubmit>
                    </form>
                    @if ($user->avatar_path)
                        <form method="POST" action="{{ route('profile.photo.destroy') }}" data-confirm="Remove your profile picture?">
                            @csrf
                            @method('DELETE')
                            <button type="submit">Remove</button>
                        </form>
                    @endif
                </div>
                @error('photo', 'updatePhoto') <div class="small text-danger text-center" style="max-width: 180px">{{ $message }}</div> @enderror
            </div>
            <div class="me-auto">
                <h1 class="h3 mb-0">{{ $user->name }}</h1>
                <div class="text-secondary">{{ $user->email }}</div>
                <div class="small text-secondary mt-1">
                    {{ $user->isAdmin() ? 'Administrator' : 'Business owner' }} · member since {{ $user->created_at->format('F Y') }}
                    @if ($user->last_login_at) · last login {{ $user->last_login_at->diffForHumans() }} @endif
                </div>
            </div>
            @if ($company)
                <div class="text-md-end">
                    <div class="small text-secondary">Business</div>
                    <div class="fw-semibold">{{ $company->name }}</div>
                </div>
            @endif
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-3 d-none d-lg-block">
            <nav class="profile-nav sticky-top" aria-label="Profile sections">
                <a href="#personal"><i class="bi bi-person"></i>Personal information</a>
                @if ($company)
                    <a href="#business"><i class="bi bi-building"></i>Business details</a>
                @endif
                <a href="#password"><i class="bi bi-shield-lock"></i>Password</a>
                @if ($company)
                    <a href="#billing"><i class="bi bi-credit-card"></i>Plan &amp; billing</a>
                @endif
            </nav>
        </div>

        <div class="col-lg-9">
            {{-- Personal information (Fortify: PUT /user/profile-information) --}}
            <section id="personal" class="card mb-4 profile-section">
                <div class="card-body p-4">
                    <h2 class="h5 mb-1">Personal information</h2>
                    <p class="small text-secondary mb-3">Your name and the email you log in with. Invoices and password reset links go to this email.</p>
                    <form method="POST" action="{{ route('user-profile-information.update') }}" novalidate>
                        @csrf
                        @method('PUT')
                        <div class="row g-2">
                            <div class="col-md-6">
                                <div class="form-floating">
                                    <input id="profile_name" name="name" value="{{ $personalOld('name', $user->name) }}" placeholder="Name" required autocomplete="name"
                                           class="form-control @error('name', 'updateProfileInformation') is-invalid @enderror">
                                    <label for="profile_name">Full name</label>
                                    @error('name', 'updateProfileInformation') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-floating">
                                    <input id="profile_email" type="email" name="email" value="{{ $personalOld('email', $user->email) }}" placeholder="Email" required autocomplete="email"
                                           class="form-control @error('email', 'updateProfileInformation') is-invalid @enderror">
                                    <label for="profile_email">Email</label>
                                    @error('email', 'updateProfileInformation') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                            </div>
                        </div>
                        <div class="text-end mt-3">
                            <button type="submit" class="btn btn-primary">Save changes</button>
                        </div>
                    </form>
                </div>
            </section>

            @if ($company)
                {{-- Business details --}}
                <section id="business" class="card mb-4 profile-section">
                    <div class="card-body p-4">
                        <h2 class="h5 mb-1">Business details</h2>
                        <p class="small text-secondary mb-3">Shown on your reports and on your Stripe invoices.</p>
                        <form method="POST" action="{{ route('profile.business') }}" novalidate>
                            @csrf
                            @method('PATCH')
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input id="business_name" name="name" value="{{ $businessOld('name', $company->name) }}" placeholder="Business name" required
                                               class="form-control @error('name', 'updateBusiness') is-invalid @enderror">
                                        <label for="business_name">Business name</label>
                                        @error('name', 'updateBusiness') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input id="legal_name" name="legal_name" value="{{ $businessOld('legal_name', $company->legal_name) }}" placeholder="Legal name"
                                               class="form-control @error('legal_name', 'updateBusiness') is-invalid @enderror">
                                        <label for="legal_name">Legal name <span class="fw-normal">(if different)</span></label>
                                        @error('legal_name', 'updateBusiness') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <select id="business_type" name="business_type" class="form-select @error('business_type', 'updateBusiness') is-invalid @enderror" required>
                                            <option value="" disabled @selected(! $businessOld('business_type', $company->business_type))>Choose...</option>
                                            @foreach (config('business.types') as $value => $label)
                                                <option value="{{ $value }}" @selected($businessOld('business_type', $company->business_type) === $value)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <label for="business_type">Business type</label>
                                        @error('business_type', 'updateBusiness') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <select id="industry" name="industry" class="form-select @error('industry', 'updateBusiness') is-invalid @enderror" required>
                                            <option value="" disabled @selected(! $businessOld('industry', $company->industry))>Choose...</option>
                                            @foreach (config('business.industries') as $group => $industries)
                                                <optgroup label="{{ $group }}">
                                                    @foreach ($industries as $industry)
                                                        <option value="{{ $industry }}" @selected($businessOld('industry', $company->industry) === $industry)>{{ $industry }}</option>
                                                    @endforeach
                                                </optgroup>
                                            @endforeach
                                        </select>
                                        <label for="industry">Industry</label>
                                        @error('industry', 'updateBusiness') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-floating">
                                        <input id="phone" type="tel" name="phone" value="{{ $businessOld('phone', $company->phone) }}" placeholder="Phone" autocomplete="tel"
                                               class="form-control @error('phone', 'updateBusiness') is-invalid @enderror">
                                        <label for="phone">Phone <span class="fw-normal">(optional)</span></label>
                                        @error('phone', 'updateBusiness') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-floating">
                                        <input id="city" name="city" value="{{ $businessOld('city', $company->city) }}" placeholder="City" autocomplete="address-level2"
                                               class="form-control @error('city', 'updateBusiness') is-invalid @enderror">
                                        <label for="city">City</label>
                                        @error('city', 'updateBusiness') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-floating">
                                        <select id="state" name="state" class="form-select @error('state', 'updateBusiness') is-invalid @enderror" required autocomplete="address-level1">
                                            <option value="" disabled @selected(! $businessOld('state', $company->state))>Choose...</option>
                                            @foreach (config('business.states') as $code => $stateName)
                                                <option value="{{ $code }}" @selected($businessOld('state', $company->state) === $code)>{{ $stateName }}</option>
                                            @endforeach
                                        </select>
                                        <label for="state">State</label>
                                        @error('state', 'updateBusiness') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <select id="fiscal_year_start_month" name="fiscal_year_start_month" class="form-select" required aria-describedby="fiscal-help">
                                            @foreach (range(1, 12) as $month)
                                                <option value="{{ $month }}" @selected((int) $businessOld('fiscal_year_start_month', $company->fiscal_year_start_month) === $month)>{{ date('F', mktime(0, 0, 0, $month, 1)) }}</option>
                                            @endforeach
                                        </select>
                                        <label for="fiscal_year_start_month">Fiscal year starts in</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <select id="accounting_method" name="accounting_method" class="form-select" required>
                                            <option value="cash" @selected($businessOld('accounting_method', $company->accounting_method) === 'cash')>Cash basis</option>
                                            <option value="accrual" @selected($businessOld('accounting_method', $company->accounting_method) === 'accrual')>Accrual basis</option>
                                        </select>
                                        <label for="accounting_method">Accounting method</label>
                                    </div>
                                </div>
                            </div>
                            <p id="fiscal-help" class="form-text mb-0 mt-2">Changing the fiscal year start changes which dates "year to date" reports cover.</p>
                            <div class="text-end mt-3">
                                <button type="submit" class="btn btn-primary">Save business details</button>
                            </div>
                        </form>
                    </div>
                </section>
            @endif

            {{-- Password (Fortify: PUT /user/password) --}}
            <section id="password" class="card mb-4 profile-section">
                <div class="card-body p-4">
                    <h2 class="h5 mb-1">Password</h2>
                    <p class="small text-secondary mb-3">Use at least 10 characters with letters and numbers.</p>
                    <form method="POST" action="{{ route('user-password.update') }}" novalidate>
                        @csrf
                        @method('PUT')
                        <div class="row g-2">
                            <div class="col-md-4">
                                <div class="form-floating password-field">
                                    <input id="current_password" type="password" name="current_password" placeholder="Current password" required autocomplete="current-password"
                                           class="form-control @error('current_password', 'updatePassword') is-invalid @enderror">
                                    <label for="current_password">Current password</label>
                                    <button type="button" class="password-toggle" data-password-toggle="#current_password" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye"></i></button>
                                    @error('current_password', 'updatePassword') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-floating password-field">
                                    <input id="new_password" type="password" name="password" placeholder="New password" required autocomplete="new-password"
                                           class="form-control @error('password', 'updatePassword') is-invalid @enderror">
                                    <label for="new_password">New password</label>
                                    <button type="button" class="password-toggle" data-password-toggle="#new_password" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye"></i></button>
                                    @error('password', 'updatePassword') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-floating">
                                    <input id="new_password_confirmation" type="password" name="password_confirmation" placeholder="Confirm new password" required autocomplete="new-password" class="form-control">
                                    <label for="new_password_confirmation">Confirm new password</label>
                                </div>
                            </div>
                        </div>
                        <div class="text-end mt-3">
                            <button type="submit" class="btn btn-primary">Change password</button>
                        </div>
                    </form>
                </div>
            </section>

            @if ($company)
                {{-- Plan & billing --}}
                <section id="billing" class="card mb-4 profile-section">
                    <div class="card-body p-4">
                        <h2 class="h5 mb-3">Plan &amp; billing</h2>
                        <div class="row g-3 mb-3">
                            <div class="col-sm-4">
                                <div class="kpi-label">Plan</div>
                                <div class="fw-semibold">{{ $plan?->name ?? '—' }} @if ($plan) <span class="text-secondary fw-normal">· ${{ number_format($plan->price, 2) }}/{{ $plan->billing_interval }}</span> @endif</div>
                            </div>
                            <div class="col-sm-4">
                                <div class="kpi-label">Status</div>
                                @if ($subscription)
                                    <span @class(['badge rounded-pill', 'text-bg-primary' => $subscription->valid(), 'text-bg-danger' => ! $subscription->valid()])>
                                        {{ ucfirst(str_replace('_', ' ', $subscription->stripe_status)) }}
                                    </span>
                                    @if ($subscription->onGracePeriod())
                                        <div class="small text-secondary">Ends {{ $subscription->ends_at->format('M j, Y') }}</div>
                                    @endif
                                @else
                                    <span class="badge rounded-pill text-bg-light border">No plan</span>
                                @endif
                            </div>
                            <div class="col-sm-4">
                                <div class="kpi-label">Payment method</div>
                                <div class="fw-semibold">
                                    @if ($company->pm_last_four)
                                        <i class="bi bi-credit-card me-1"></i>{{ ucfirst($company->pm_type) }} •••• {{ $company->pm_last_four }}
                                    @else
                                        <span class="text-secondary fw-normal">Managed in Stripe</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                        <h3 class="h6 mt-2">Add-on reports</h3>
                        @if ($addons->isNotEmpty())
                            <ul class="list-group mb-3">
                                @foreach ($addons as $addon)
                                    <li class="list-group-item d-flex align-items-center gap-2">
                                        <i class="bi bi-file-earmark-bar-graph text-secondary"></i>
                                        <a href="{{ route('reports.show', $addon->report->code) }}" class="me-auto">{{ $addon->report->name }}</a>
                                        <span class="small text-secondary">{{ $addon->price > 0 ? money($addon->price).'/mo' : 'Free' }}</span>
                                        @if ($addon->price > 0 || $addon->stripe_subscription_item_id)
                                            <form method="POST" action="{{ route('addons.destroy', $addon) }}" data-confirm="Remove {{ $addon->report->name }}? You lose access now; unused time is credited.">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-link text-danger p-0 ms-2">Remove</button>
                                            </form>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p class="small text-secondary">No add-on reports yet.</p>
                        @endif
                        <p class="small text-secondary">Invoices, card changes and cancelling the plan are handled securely by Stripe.</p>
                        <div class="d-flex flex-wrap gap-2">
                            @if ($company->hasStripeId())
                                <a href="{{ route('billing.portal') }}" class="btn btn-outline-secondary"><i class="bi bi-box-arrow-up-right me-1"></i>Manage billing &amp; invoices</a>
                            @endif
                            <a href="{{ route('reports.index') }}" class="btn btn-light border">Browse add-on reports</a>
                        </div>
                    </div>
                </section>
            @endif
        </div>
    </div>
@endsection

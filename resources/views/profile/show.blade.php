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
                        <form method="POST" action="{{ route('profile.photo.destroy') }}" data-confirm="Your initials are shown instead." data-confirm-title="Remove your picture?" data-confirm-button="Remove" data-confirm-tone="danger">
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
                                        <span class="text-secondary fw-normal">No card yet</span>
                                    @endif
                                </div>
                                @if ($stripeKey)
                                    <button type="button" class="btn btn-sm btn-link p-0" data-bs-toggle="modal" data-bs-target="#cardModal">
                                        <i class="bi bi-pencil me-1"></i>{{ $company->pm_last_four ? 'Change card' : 'Add a card' }}
                                    </button>
                                @endif
                            </div>
                        </div>

                        @if ($subscription?->onGracePeriod())
                            <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2">
                                <i class="bi bi-calendar-x"></i>
                                <span class="me-auto">Your plan is cancelled and stays open until <b>{{ $subscription->ends_at->format('M j, Y') }}</b>. You will not be charged again.</span>
                                <form method="POST" action="{{ route('billing.resume') }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-primary">Keep my plan</button>
                                </form>
                            </div>
                        @endif

                        @if ($subscription?->valid() && $plan)
                            {{-- What the next monthly payment is made of --}}
                            @php
                                $renewing = $addons->whereNull('ends_at')->where('price', '>', 0);
                                $ending = $addons->whereNotNull('ends_at');
                                $nextTotal = ($subscription->onGracePeriod() ? 0 : (float) $plan->price) + ($subscription->onGracePeriod() ? 0 : (float) $renewing->sum('price'));
                            @endphp
                            <h3 class="h6 mt-2">Your monthly charges</h3>
                            <div class="table-responsive mb-2">
                                <table class="table report-table align-middle mb-0 billing-breakdown">
                                    <thead><tr><th>Item</th><th>Status</th><th class="num">Per month</th></tr></thead>
                                    <tbody>
                                        <tr>
                                            <td><i class="bi bi-box-seam me-1 text-secondary"></i>{{ $plan->name }} plan</td>
                                            <td>
                                                @if ($subscription->onGracePeriod())
                                                    <span class="badge rounded-pill text-bg-warning">Ends {{ $subscription->ends_at->format('M j, Y') }}</span>
                                                @else
                                                    <span class="badge rounded-pill text-bg-success">Renews monthly</span>
                                                @endif
                                            </td>
                                            <td class="num">{{ money($plan->price) }}</td>
                                        </tr>
                                        @foreach ($renewing as $addon)
                                            <tr>
                                                <td><i class="bi bi-file-earmark-bar-graph me-1 text-secondary"></i>{{ $addon->report->name }} <span class="small text-secondary">add-on</span></td>
                                                <td><span class="badge rounded-pill text-bg-success">Renews monthly</span></td>
                                                <td class="num">{{ money($addon->price) }}</td>
                                            </tr>
                                        @endforeach
                                        @foreach ($ending as $addon)
                                            <tr class="text-secondary">
                                                <td><i class="bi bi-file-earmark-bar-graph me-1"></i>{{ $addon->report->name }} <span class="small">add-on</span></td>
                                                <td><span class="badge rounded-pill text-bg-light border">Removed · open until {{ $addon->ends_at->format('M j, Y') }}</span></td>
                                                <td class="num">{{ money(0) }}</td>
                                            </tr>
                                        @endforeach
                                        <tr class="row-total">
                                            <td colspan="2">
                                                Next payment
                                                @if ($nextBilling && ! $subscription->onGracePeriod())
                                                    <span class="fw-normal text-secondary">· {{ $nextBilling->format('M j, Y') }}</span>
                                                @endif
                                            </td>
                                            <td class="num">{{ money($nextTotal) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <p class="small text-secondary mb-3">
                                <i class="bi bi-info-circle me-1"></i><b>All payments are non-refundable.</b>
                                Removing an add-on or cancelling the plan stops the next charges only; what you already paid stays open until the end of that month.
                                Taxes, if any, are shown on each invoice.
                            </p>
                        @endif

                        <h3 class="h6 mt-2">Add-on reports</h3>
                        @if ($addons->isNotEmpty())
                            <ul class="list-group mb-3">
                                @foreach ($addons as $addon)
                                    <li class="list-group-item d-flex align-items-center gap-2">
                                        <i class="bi bi-file-earmark-bar-graph text-secondary"></i>
                                        <a href="{{ route('reports.show', $addon->report->code) }}" class="me-auto">{{ $addon->report->name }}</a>
                                        <span class="small text-secondary">{{ $addon->price > 0 ? money($addon->price).'/mo' : 'Free' }}</span>
                                        @if ($addon->ends_at)
                                            <span class="badge rounded-pill text-bg-light border fw-normal ms-2">Open until {{ $addon->ends_at->format('M j, Y') }} · not renewing</span>
                                        @elseif ($addon->price > 0 || $addon->stripe_subscription_item_id)
                                            <form method="POST" action="{{ route('addons.destroy', $addon) }}" data-confirm="{{ $addon->report->name }} stops renewing. It stays open until the end of the month you already paid for. Payments are non-refundable, so there is no refund or credit." data-confirm-title="Remove this add-on?" data-confirm-button="Remove add-on" data-confirm-tone="danger">
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
                        <h3 class="h6 mt-4">Invoices</h3>
                        @if ($invoicesFailed)
                            <p class="small text-secondary">Your invoices could not be loaded right now. Please refresh in a moment.</p>
                        @elseif ($invoices->isEmpty())
                            <p class="small text-secondary">No invoices yet.</p>
                        @else
                            <div class="table-responsive mb-3">
                                <table class="table report-table table-hover align-middle mb-0 invoice-list">
                                    <thead><tr><th>Date</th><th>Invoice</th><th class="num">Amount</th><th>Status</th><th class="text-end"><span class="visually-hidden">Open</span></th></tr></thead>
                                    <tbody>
                                        @foreach ($invoices as $invoice)
                                            <tr>
                                                <td class="text-nowrap">{{ $invoice->date()->format('M j, Y') }}</td>
                                                <td class="text-nowrap">{{ $invoice->number ?? '—' }}</td>
                                                <td class="num">{{ $invoice->total() }}</td>
                                                <td>
                                                    <span @class(['badge rounded-pill', 'text-bg-success' => $invoice->isPaid(), 'text-bg-warning' => ! $invoice->isPaid()])>
                                                        {{ $invoice->isPaid() ? 'Paid' : ucfirst((string) $invoice->status) }}
                                                    </span>
                                                </td>
                                                <td class="text-end text-nowrap">
                                                    <a href="{{ route('billing.invoice', $invoice->id) }}" class="btn btn-sm btn-light border">View</a>
                                                    <a href="{{ route('billing.invoice', [$invoice->id, 'print' => 1]) }}" target="_blank" rel="noopener" class="btn btn-sm btn-light border" title="Print or save as PDF"><i class="bi bi-printer"></i><span class="visually-hidden">Print</span></a>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <a href="{{ route('reports.index') }}" class="btn btn-light border">Browse add-on reports</a>
                            @if ($subscription?->valid() && ! $subscription->onGracePeriod())
                                <form method="POST" action="{{ route('billing.cancel') }}" class="ms-auto"
                                      data-confirm="Your plan and add-ons stay open until the end of the month you already paid for. After that you will not be charged again. Payments are non-refundable. You can keep your plan any time before that date."
                                      data-confirm-title="Cancel your plan?" data-confirm-button="Cancel plan" data-confirm-tone="danger">
                                    @csrf
                                    <button type="submit" class="btn btn-link text-danger">Cancel plan</button>
                                </form>
                            @endif
                        </div>
                    </div>
                </section>

                @if ($stripeKey)
                    {{-- Change card: Stripe's card field inside our own window (the card goes straight to Stripe) --}}
                    <div class="modal fade" id="cardModal" tabindex="-1" aria-labelledby="cardModalTitle" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered">
                            <form class="modal-content addon-modal" data-card-form data-js-submit data-key="{{ $stripeKey }}"
                                  data-intent="{{ route('billing.card.intent') }}" data-save="{{ route('billing.card.update') }}" data-return="{{ route('profile.show') }}">
                                @csrf
                                <div class="modal-header border-0 pb-0">
                                    <div class="d-flex align-items-center gap-3">
                                        <span class="addon-modal-icon"><i class="bi bi-credit-card"></i></span>
                                        <div>
                                            <h2 class="modal-title h5 mb-0" id="cardModalTitle">{{ $company->pm_last_four ? 'Change card' : 'Add a card' }}</h2>
                                            <div class="small text-secondary">Used for your plan and every add-on</div>
                                        </div>
                                    </div>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <div data-card-element class="pay-element"></div>
                                    <div class="alert alert-danger small mt-3 mb-0 d-none" role="alert" data-card-message></div>
                                </div>
                                <div class="modal-footer border-0 pt-0">
                                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-primary" data-card-button disabled>
                                        <span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true" data-card-spinner></span>Save card
                                    </button>
                                </div>
                                <div class="addon-modal-foot small text-secondary"><i class="bi bi-shield-lock me-1"></i>Card details go straight to Stripe and are never stored on our servers.</div>
                            </form>
                        </div>
                    </div>
                    <script src="https://js.stripe.com/v3/"></script>
                    <script src="{{ asset('js/billing.js') }}?v={{ @filemtime(public_path('js/billing.js')) ?: '1' }}" defer></script>
                @endif
            @endif
        </div>
    </div>
@endsection

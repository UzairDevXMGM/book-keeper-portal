@extends('layouts.auth', ['wide' => true])

@section('title', 'Create account')

@section('content')
    <h1 class="h3 mb-1">Create your account</h1>
    <p class="text-secondary small mb-3">One page: your details and your card. Your account opens as soon as the payment goes through.</p>

    @if ($plan)
        <div class="card pay-plan mb-2">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center gap-3">
                    <div>
                        <div class="fw-semibold">{{ $plan->name }} plan</div>
                        <div class="small text-secondary">Billed every {{ $plan->billing_interval }} · cancel any time</div>
                    </div>
                    <div class="text-end text-nowrap">
                        <span class="plan-price" style="font-size: 1.9rem">{{ money($plan->price) }}</span>
                        <span class="text-secondary small">/{{ $plan->billing_interval }}</span>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('register.store') }}" novalidate data-signup-form data-js-submit
          data-key="{{ $stripeKey }}" data-amount="{{ $plan ? (int) round($plan->price * 100) : 0 }}"
          data-complete="{{ route('register.complete') }}">
        @csrf
        <div class="alert alert-danger small d-none" role="alert" data-signup-message></div>

        <div class="form-section">Your details</div>
        <div class="row g-2">
            <div class="col-sm-6">
                <div class="form-floating">
                    <input id="name" type="text" name="name" value="{{ old('name') }}" placeholder="Your name"
                           class="form-control @error('name') is-invalid @enderror" required autofocus autocomplete="name">
                    <label for="name">Your name</label>
                    @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>
            <div class="col-sm-6">
                <div class="form-floating">
                    <input id="email" type="email" name="email" value="{{ old('email') }}" placeholder="Email"
                           class="form-control @error('email') is-invalid @enderror" required autocomplete="username">
                    <label for="email">Email</label>
                    @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>
            <div class="col-sm-6">
                <div class="form-floating password-field">
                    <input id="password" type="password" name="password" placeholder="Password"
                           class="form-control @error('password') is-invalid @enderror"
                           required autocomplete="new-password" aria-describedby="password-help">
                    <label for="password">Password</label>
                    <button type="button" class="password-toggle" data-password-toggle="#password" aria-label="Show password" aria-pressed="false">
                        <i class="bi bi-eye"></i>
                    </button>
                    @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>
            <div class="col-sm-6">
                <div class="form-floating">
                    <input id="password_confirmation" type="password" name="password_confirmation" placeholder="Confirm password"
                           class="form-control" required autocomplete="new-password">
                    <label for="password_confirmation">Confirm password</label>
                </div>
            </div>
            <div class="col-12">
                <div id="password-help" class="form-text mt-0">At least 10 characters, with letters and numbers.</div>
            </div>
        </div>

        <div class="form-section">Your business</div>
        <div class="row g-2">
            <div class="col-sm-7">
                <div class="form-floating">
                    <input id="company_name" type="text" name="company_name" value="{{ old('company_name') }}" placeholder="Business name"
                           class="form-control @error('company_name') is-invalid @enderror" required autocomplete="organization">
                    <label for="company_name">Business name</label>
                    @error('company_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>
            <div class="col-sm-5">
                <div class="form-floating">
                    <input id="phone" type="tel" name="phone" value="{{ old('phone') }}" placeholder="Phone"
                           class="form-control @error('phone') is-invalid @enderror" autocomplete="tel">
                    <label for="phone">Phone <span class="fw-normal">(optional)</span></label>
                    @error('phone') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>
            <div class="col-sm-6">
                <div class="form-floating">
                    <select id="business_type" name="business_type" class="form-select @error('business_type') is-invalid @enderror" required>
                        <option value="" disabled @selected(! old('business_type'))>Choose...</option>
                        @foreach (config('business.types') as $value => $label)
                            <option value="{{ $value }}" @selected(old('business_type') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <label for="business_type">Business type</label>
                    @error('business_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>
            <div class="col-sm-6">
                <div class="form-floating">
                    <select id="industry" name="industry" class="form-select @error('industry') is-invalid @enderror" required>
                        <option value="" disabled @selected(! old('industry'))>Choose...</option>
                        @foreach (config('business.industries') as $group => $industries)
                            <optgroup label="{{ $group }}">
                                @foreach ($industries as $industry)
                                    <option value="{{ $industry }}" @selected(old('industry') === $industry)>{{ $industry }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    <label for="industry">Industry</label>
                    @error('industry') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>
            <div class="col-sm-6">
                <div class="form-floating">
                    <select id="state" name="state" class="form-select @error('state') is-invalid @enderror" required autocomplete="address-level1">
                        <option value="" disabled @selected(! old('state'))>Choose...</option>
                        @foreach (config('business.states') as $code => $stateName)
                            <option value="{{ $code }}" @selected(old('state') === $code)>{{ $stateName }}</option>
                        @endforeach
                    </select>
                    <label for="state">State</label>
                    @error('state') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>
            <div class="col-sm-6">
                <div class="form-floating">
                    <select id="fiscal_year_start_month" name="fiscal_year_start_month" class="form-select @error('fiscal_year_start_month') is-invalid @enderror" required>
                        @foreach (range(1, 12) as $month)
                            <option value="{{ $month }}" @selected((int) old('fiscal_year_start_month', 1) === $month)>{{ date('F', mktime(0, 0, 0, $month, 1)) }}</option>
                        @endforeach
                    </select>
                    <label for="fiscal_year_start_month">Fiscal year starts in</label>
                    @error('fiscal_year_start_month') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>
        </div>

        @if ($stripeKey && $plan)
            {{-- Stripe's own address and card fields inside our form: the card goes straight to Stripe --}}
            <div class="form-section">Billing address</div>
            <div data-address-element class="pay-element"></div>

            <div class="form-section">Card details</div>
            <div data-payment-element class="pay-element"></div>
            <div class="small text-secondary mt-2"><i class="bi bi-shield-lock me-1"></i>Card details go straight to Stripe and are never stored on our servers.</div>
        @else
            <div class="alert alert-secondary small mt-3 mb-0">Online payment is not set up yet, so new sign-ups are paused. Please check back soon.</div>
        @endif

        <div class="form-check small mt-3 mb-3">
            <input class="form-check-input @error('terms') is-invalid @enderror" type="checkbox" name="terms" id="terms" value="1" @checked(old('terms'))>
            <label class="form-check-label" for="terms">
                I agree to the
                <a href="{{ config('bookkeeper.website_url') }}/terms-of-service.html" target="_blank" rel="noopener">Terms of Service</a>
                and
                <a href="{{ config('bookkeeper.website_url') }}/privacy-policy.html" target="_blank" rel="noopener">Privacy Policy</a>,
                and I understand that <b>all payments are non-refundable</b>.
            </label>
            @error('terms') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-100" data-signup-button @disabled(! $stripeKey || ! $plan)>
            <i class="bi bi-lock-fill me-1"></i>{{ $plan ? 'Pay '.money($plan->price).' and create my account' : 'Create my account' }}
        </button>
        @if ($plan)
            <p class="small text-secondary text-center mt-2 mb-0">{{ money($plan->price) }} today, then every {{ $plan->billing_interval }} until you cancel. <b>All payments are non-refundable.</b></p>
        @endif
    </form>

    <p class="text-center text-secondary small mt-3 mb-0">
        Already have an account? <a href="{{ route('login') }}">Log in</a>
    </p>

    {{-- Progress while the account is being created --}}
    <div class="signup-progress d-none" data-signup-progress role="dialog" aria-modal="true" aria-labelledby="signupProgressTitle" aria-live="polite">
        <div class="signup-progress-card">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="spinner-border text-primary" aria-hidden="true"></span>
                <div>
                    <div class="fw-semibold" id="signupProgressTitle" data-progress-title>Creating your account…</div>
                    <div class="small text-secondary">Please keep this page open.</div>
                </div>
            </div>
            <div class="progress mb-3" role="progressbar" aria-label="Sign-up progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-progress-wrap>
                <div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 0%" data-progress-bar></div>
            </div>
            <ol class="signup-progress-steps list-unstyled small mb-0">
                <li data-progress-step="check"><i class="bi bi-circle"></i>Checking your details</li>
                <li data-progress-step="card"><i class="bi bi-circle"></i>Securing your card</li>
                <li data-progress-step="pay"><i class="bi bi-circle"></i>Taking the first payment</li>
                <li data-progress-step="books"><i class="bi bi-circle"></i>Setting up your books</li>
            </ol>
        </div>
    </div>

    @if ($stripeKey && $plan)
        <script src="https://js.stripe.com/v3/"></script>
        <script src="{{ asset('js/signup.js') }}?v={{ @filemtime(public_path('js/signup.js')) ?: '1' }}" defer></script>
    @endif
@endsection

@extends('layouts.auth', ['wide' => true])

@section('title', 'Create account')

@section('content')
    <div class="auth-steps" aria-label="Sign-up steps">
        <span class="active">1. Your account</span>
        <span>2. Payment</span>
    </div>

    <h1 class="h3 mb-1">Create your account</h1>
    <p class="text-secondary small mb-0">
        @if ($plan)
            {{ $plan->name }} plan, ${{ number_format($plan->price, 2) }}/{{ $plan->billing_interval }}. You'll pay securely with Stripe on the next step.
        @else
            You'll choose how to pay on the next step.
        @endif
    </p>

    <form method="POST" action="{{ route('register') }}" novalidate>
        @csrf

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

        <div class="form-check small mt-3 mb-3">
            <input class="form-check-input @error('terms') is-invalid @enderror" type="checkbox" name="terms" id="terms" value="1" @checked(old('terms'))>
            <label class="form-check-label" for="terms">
                I agree to the
                <a href="{{ config('bookkeeper.website_url') }}/terms-of-service.html" target="_blank" rel="noopener">Terms of Service</a>
                and
                <a href="{{ config('bookkeeper.website_url') }}/privacy-policy.html" target="_blank" rel="noopener">Privacy Policy</a>.
            </label>
            @error('terms') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <button type="submit" class="btn btn-primary w-100">
            <span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true"></span>
            Continue to payment
        </button>
    </form>

    <p class="text-center text-secondary small mt-3 mb-0">
        Already have an account? <a href="{{ route('login') }}">Log in</a>
    </p>
@endsection

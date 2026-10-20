@extends('layouts.auth', ['wide' => true])

@section('title', 'Payment')

@section('content')
    <div class="auth-steps" aria-label="Sign-up steps">
        <span>1. Your account</span>
        <span class="active">2. Payment</span>
    </div>

    <h1 class="h3 mb-1">Start your plan</h1>
    <p class="text-secondary small mb-3">for <strong>{{ $company->name }}</strong></p>

    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    @if ($plan)
        <div class="card pay-plan mb-3">
            <div class="card-body p-3 p-md-4">
                <div class="d-flex justify-content-between align-items-start gap-3">
                    <div>
                        <h2 class="h5 mb-1">{{ $plan->name }} plan</h2>
                        <div class="small text-secondary">Billed every {{ $plan->billing_interval }} · cancel any time</div>
                    </div>
                    <div class="text-end text-nowrap">
                        <span class="plan-price" style="font-size: 2.2rem">{{ money($plan->price) }}</span>
                        <span class="text-secondary">/{{ $plan->billing_interval }}</span>
                    </div>
                </div>
                @if ($plan->reports->isNotEmpty())
                    <ul class="pay-plan-list list-unstyled small mb-0 mt-3">
                        @foreach ($plan->reports as $report)
                            <li><i class="bi bi-check-circle-fill text-primary me-1"></i>{{ $report->name }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    @endif

    @if ($clientSecret)
        {{-- Card and address boxes are Stripe's own fields shown inside our page: the card number goes from the
             browser straight to Stripe, so it never reaches this server --}}
        <form class="card pay-form" data-payment-form data-js-submit
              data-key="{{ $stripeKey }}" data-secret="{{ $clientSecret }}"
              data-subscribe="{{ route('billing.subscribe') }}" data-confirm-url="{{ route('billing.confirm') }}"
              data-return="{{ route('billing.show') }}" data-name="{{ $company->name }}">
            @csrf
            <div class="card-body p-3 p-md-4">
                <div class="form-section mt-0">Billing address</div>
                <div data-address-element class="pay-element"></div>

                <div class="form-section">Card details</div>
                <div data-payment-element class="pay-element"></div>

                <div class="alert alert-danger small mt-3 mb-0 d-none" role="alert" data-payment-message></div>

                <button type="submit" class="btn btn-primary btn-lg w-100 mt-3" data-payment-button disabled>
                    <span class="spinner-border spinner-border-sm me-1" aria-hidden="true" data-payment-spinner></span>
                    <span data-payment-label>Loading secure payment form…</span>
                    <span class="d-none" data-payment-ready-label><i class="bi bi-lock-fill me-1"></i>Pay {{ money($plan->price) }} and start</span>
                </button>

                <div class="pay-trust small text-secondary mt-3">
                    <span><i class="bi bi-shield-lock"></i>Card details go straight to Stripe and are never stored on our servers</span>
                    <span><i class="bi bi-arrow-repeat"></i>{{ money($plan->price) }} every {{ $plan->billing_interval }} until you cancel</span>
                    <span><i class="bi bi-info-circle"></i><b>All payments are non-refundable.</b> Cancelling stops the next charges; the month already paid stays open.</span>
                </div>
            </div>
            <div class="pay-powered small text-secondary"><i class="bi bi-lock-fill me-1"></i>Secure payment powered by <b>Stripe</b></div>
        </form>

        <script src="https://js.stripe.com/v3/"></script>
        <script src="{{ asset('js/billing.js') }}?v={{ @filemtime(public_path('js/billing.js')) ?: '1' }}" defer></script>
    @elseif ($paymentError)
        <div class="alert alert-danger mb-0">{{ $paymentError }}</div>
    @elseif (! session('error'))
        <div class="alert alert-secondary mb-0">Online payment is not set up yet. Please check back soon.</div>
    @endif

    <form method="POST" action="{{ route('logout') }}" class="text-center mt-3">
        @csrf
        <button type="submit" class="btn btn-link text-secondary">Log out</button>
    </form>
@endsection

@extends('layouts.auth')

@section('title', 'Payment')

@section('content')
    <div class="auth-steps" aria-label="Sign-up steps">
        <span>1. Your account</span>
        <span class="active">2. Payment</span>
    </div>

    <h1 class="h3 mb-1">Start your plan</h1>
    <p class="text-secondary small mb-3">for <strong>{{ $company->name }}</strong></p>

    @if (request('cancelled'))
        <div class="alert alert-warning">Payment was cancelled. You can try again whenever you're ready.</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    @if ($plan)
        <div class="card mb-4">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <h2 class="h4 mb-0">{{ $plan->name }}</h2>
                    <div class="text-end">
                        <span class="plan-price">${{ number_format($plan->price, 0) }}</span>
                        <span class="text-secondary">/{{ $plan->billing_interval }}</span>
                    </div>
                </div>
                <ul class="list-unstyled mb-3">
                    @foreach ($plan->reports as $report)
                        <li class="mb-2"><i class="bi bi-check-circle-fill text-primary me-2"></i>{{ $report->name }}</li>
                    @endforeach
                </ul>
                <p class="small text-secondary mb-0">More reports are available as add-ons once you're in.</p>
            </div>
        </div>
    @endif

    @if ($stripeReady && $plan?->stripe_price_id)
        <a href="{{ route('billing.checkout') }}" class="btn btn-primary w-100">
            <i class="bi bi-lock-fill me-1"></i>Pay securely with Stripe
        </a>
        <p class="small text-secondary text-center mt-2 mb-0">You'll be taken to Stripe's secure checkout page.</p>
    @elseif (! session('error'))
        <div class="alert alert-secondary mb-0">Online payment is not set up yet. Please check back soon.</div>
    @endif

    <form method="POST" action="{{ route('logout') }}" class="text-center mt-4">
        @csrf
        <button type="submit" class="btn btn-link text-secondary">Log out</button>
    </form>
@endsection

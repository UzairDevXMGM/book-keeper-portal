@extends('layouts.app')

@section('title', 'Plans')

@section('content')
    <div class="page-head">
        <div>
            <h1 class="h3">Plans</h1>
            <div class="small text-secondary">What new customers pay at sign-up and which reports it includes. Prices are billed through Stripe.</div>
        </div>
        <form method="POST" action="{{ route('admin.plans.sync') }}">
            @csrf
            <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-repeat me-1"></i>Sync with Stripe</button>
        </form>
    </div>

    <div class="row row-cols-1 row-cols-lg-2 g-3">
        @foreach ($plans as $plan)
            <div class="col">
                <div class="card h-100">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <h2 class="h4 mb-0">{{ $plan->name }}</h2>
                                <span class="badge {{ $plan->is_active ? 'text-bg-primary' : 'text-bg-light border' }}">{{ $plan->is_active ? 'On sale' : 'Hidden' }}</span>
                            </div>
                            <div class="text-end">
                                <span class="plan-price">${{ number_format($plan->price, 0) }}</span><span class="text-secondary">/{{ $plan->billing_interval }}</span>
                            </div>
                        </div>
                        <p class="text-secondary small">{{ $plan->description }}</p>
                        <ul class="list-unstyled small mb-3">
                            <li><i class="bi bi-file-earmark-text me-2 text-secondary"></i>{{ $plan->reports_count }} reports included</li>
                            <li><i class="bi bi-people me-2 text-secondary"></i>{{ number_format($subscribers[$plan->stripe_price_id] ?? 0) }} active subscribers</li>
                            <li>
                                <i class="bi bi-stripe me-2 text-secondary"></i>
                                @if ($plan->stripe_price_id)
                                    Stripe price <code>{{ $plan->stripe_price_id }}</code>
                                @else
                                    <span class="text-danger">No Stripe price yet. Click “Sync with Stripe”.</span>
                                @endif
                            </li>
                        </ul>
                        <a href="{{ route('admin.plans.edit', $plan) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i>Edit plan</a>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endsection

@extends('layouts.app')

@section('title', $report->name)

@section('content')
    <a href="{{ route('reports.index') }}" class="small"><i class="bi bi-arrow-left me-1"></i>Reports</a>
    <div class="card mt-2" style="max-width: 680px">
        <div class="card-body p-4">
            <span class="kpi-icon" style="width: 3rem; height: 3rem; font-size: 1.3rem"><i class="bi bi-stars"></i></span>
            <h1 class="h4 mt-3 mb-1">{{ $report->name }}</h1>
            <p class="text-secondary">{{ $report->description }}</p>

            <div class="d-flex align-items-baseline gap-2 mb-3">
                <span class="plan-price" style="font-size: 2.25rem">${{ number_format($report->addon_price, 2) }}</span>
                <span class="text-secondary">/month, added to your current plan</span>
            </div>

            <ul class="list-unstyled small text-secondary mb-4">
                <li><i class="bi bi-check2 me-2 text-primary"></i>Charged today for the rest of this billing month, then monthly with your plan</li>
                <li><i class="bi bi-check2 me-2 text-primary"></i>Uses the card already on file with Stripe</li>
                <li><i class="bi bi-check2 me-2 text-primary"></i>Remove it any time from My profile → Plan &amp; billing</li>
            </ul>

            @if ($report->stripe_price_id)
                <form method="POST" action="{{ route('addons.store', $report->code) }}"
                      data-confirm="Add {{ $report->name }} for ${{ number_format($report->addon_price, 2) }}/month? Your card is charged a prorated amount today.">
                    @csrf
                    <button type="submit" class="btn btn-primary">
                        <span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true"></span>
                        <i class="bi bi-plus-lg me-1"></i>Add to my plan
                    </button>
                </form>
            @else
                <div class="alert alert-secondary small mb-0">This add-on is not on sale yet. Please check back soon.</div>
            @endif
        </div>
    </div>
@endsection

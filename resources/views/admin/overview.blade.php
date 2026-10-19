@extends('layouts.app')

@section('title', 'Admin overview')

@section('content')
    <div class="page-head">
        <div>
            <h1 class="h3">Overview</h1>
            <div class="small text-secondary">Customers, plans and monthly recurring revenue.</div>
        </div>
        <a href="{{ route('admin.guide') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-book me-1"></i>Admin guide</a>
    </div>

    <div class="row row-cols-2 row-cols-lg-4 g-2 g-md-3 mb-4">
        <div class="col"><x-kpi label="Customers" :value="number_format($customers)" /></div>
        <div class="col"><x-kpi label="Paying (active plan)" :value="number_format($paying)" /></div>
        <div class="col"><x-kpi label="Monthly recurring revenue" :value="money($mrr)" /></div>
        <div class="col"><x-kpi label="Signed up, not paid" :value="number_format($unpaid)" :tone="$unpaid ? 'bad' : null" /></div>
    </div>

    <div class="card">
        <div class="card-header bg-white fw-semibold">Customers <span class="text-secondary fw-normal small">· newest first</span></div>
        <div class="table-responsive">
            <table class="table report-table table-hover mb-0">
                <thead><tr><th>Business</th><th>Owner</th><th>Industry</th><th>State</th><th>Plan</th><th>Signed up</th></tr></thead>
                <tbody>
                    @forelse ($recent as $company)
                        @php $active = $company->subscriptions->first(fn ($s) => in_array($s->stripe_status, ['active', 'trialing'], true)); @endphp
                        <tr>
                            <td>{{ $company->name }}</td>
                            <td>{{ $company->owner?->email }}</td>
                            <td>{{ $company->industry ?? '—' }}</td>
                            <td>{{ $company->state ?? '—' }}</td>
                            <td>
                                @if ($active)
                                    <span class="badge text-bg-primary">{{ ucfirst($active->stripe_status) }}</span>
                                @else
                                    <span class="badge text-bg-light border">Not paid</span>
                                @endif
                            </td>
                            <td class="text-nowrap">{{ $company->created_at->format('M j, Y') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-secondary py-4">No customers yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @include('partials.pagination', ['paginator' => $recent, 'label' => 'customers'])
@endsection

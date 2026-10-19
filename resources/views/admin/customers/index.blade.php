@extends('layouts.app')

@section('title', 'Customers')

@section('content')
    <div class="page-head">
        <div>
            <h1 class="h3">Customers</h1>
            <div class="small text-secondary">Every business that signed up, newest first.</div>
        </div>
    </div>

    <form method="GET" class="card card-body report-toolbar mb-3 d-flex flex-row flex-wrap align-items-end gap-2">
        <div>
            <label for="q" class="form-label small mb-1">Search</label>
            <input id="q" type="search" name="q" value="{{ $search }}" placeholder="Business, owner or email" class="form-control form-control-sm" style="min-width: 260px">
        </div>
        <div class="btn-group btn-group-sm segmented" role="group" aria-label="Status">
            @foreach ($filters as $value => $label)
                <a href="{{ route('admin.customers.index', ['status' => $value, 'q' => $search ?: null]) }}" @class(['btn', 'active' => $filter === $value])>{{ $label }}</a>
            @endforeach
        </div>
        <button type="submit" class="btn btn-sm btn-primary">Search</button>
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table report-table table-hover mb-0">
                <thead><tr><th>Business</th><th>Owner</th><th>Industry</th><th>Plan</th><th class="num">Entries</th><th class="num">Uploads</th><th>Signed up</th></tr></thead>
                <tbody>
                    @forelse ($companies as $company)
                        @php $active = $company->subscriptions->first(fn ($s) => in_array($s->stripe_status, ['active', 'trialing'], true)); @endphp
                        <tr>
                            <td>
                                <a href="{{ route('admin.customers.show', $company) }}" class="d-flex align-items-center gap-2">
                                    <x-avatar :name="$company->name" square />{{ $company->name }}
                                </a>
                            </td>
                            <td class="small">
                                {{ $company->owner?->email }}
                                @if ($company->owner && ! $company->owner->is_active) <span class="badge text-bg-secondary ms-1">Login off</span> @endif
                            </td>
                            <td class="small">{{ $company->industry ?? '—' }}</td>
                            <td>
                                @if ($active)
                                    <span class="badge text-bg-primary">{{ ucfirst($active->stripe_status) }}</span>
                                @else
                                    <span class="badge text-bg-light border">Not paid</span>
                                @endif
                            </td>
                            <td class="num">{{ number_format($company->journal_entries_count) }}</td>
                            <td class="num">{{ number_format($company->uploads_count) }}</td>
                            <td class="text-nowrap small">{{ $company->created_at->format('M j, Y') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-secondary py-4">No customers match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @include('partials.pagination', ['paginator' => $companies, 'label' => 'customers'])
@endsection

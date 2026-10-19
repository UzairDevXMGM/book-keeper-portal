@extends('layouts.app')

@section('title', 'Audit log')

@section('content')
    <div class="page-head">
        <div>
            <h1 class="h3">Audit log</h1>
            <div class="small text-secondary">Sign-ins, payments, postings, uploads and admin changes. Newest first.</div>
        </div>
    </div>

    <form method="GET" class="card card-body report-toolbar mb-3 d-flex flex-row flex-wrap align-items-end gap-2">
        <div>
            <label for="action" class="form-label small mb-1">Area</label>
            <select id="action" name="action" class="form-select form-select-sm">
                <option value="">All</option>
                @foreach ($groups as $group)
                    <option value="{{ $group }}" @selected(($filters['action'] ?? '') === $group)>{{ ucfirst($group) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="company" class="form-label small mb-1">Business</label>
            <select id="company" name="company" class="form-select form-select-sm" style="max-width: 220px">
                <option value="">All</option>
                @foreach ($companies as $company)
                    <option value="{{ $company->id }}" @selected((int) ($filters['company'] ?? 0) === $company->id)>{{ $company->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="q" class="form-label small mb-1">User email</label>
            <input id="q" type="search" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control form-control-sm">
        </div>
        <div>
            <label for="start" class="form-label small mb-1">From</label>
            <input id="start" type="date" name="start" value="{{ $filters['start'] ?? '' }}" class="form-control form-control-sm">
        </div>
        <div>
            <label for="end" class="form-label small mb-1">To</label>
            <input id="end" type="date" name="end" value="{{ $filters['end'] ?? '' }}" class="form-control form-control-sm">
        </div>
        <button type="submit" class="btn btn-sm btn-primary">Filter</button>
        @if (array_filter($filters))
            <a href="{{ route('admin.audit.index') }}" class="btn btn-sm btn-link">Clear</a>
        @endif
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table report-table mb-0">
                <thead><tr><th>When</th><th>Action</th><th>User</th><th>Business</th><th>Details</th><th>IP</th></tr></thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr>
                            <td class="small text-nowrap" title="{{ $log->created_at }}">{{ $log->created_at->format('M j, Y g:i A') }}</td>
                            <td><code>{{ $log->action }}</code></td>
                            <td class="small">{{ $log->user?->email ?? 'system' }}</td>
                            <td class="small">{{ $log->company?->name ?? '—' }}</td>
                            <td class="small text-secondary" style="max-width: 340px">
                                @if ($log->details)
                                    {{ \Illuminate\Support\Str::limit(collect($log->details)->map(fn ($v, $k) => (is_int($k) ? '' : "{$k}: ").(is_array($v) ? implode(', ', array_map('strval', \Illuminate\Support\Arr::flatten($v))) : $v))->implode(' · '), 160) }}
                                @endif
                            </td>
                            <td class="small text-secondary">{{ $log->ip_address }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-secondary py-4">Nothing logged yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @include('partials.pagination', ['paginator' => $logs, 'label' => 'events'])
@endsection

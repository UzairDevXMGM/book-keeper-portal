@extends('layouts.app')

@section('title', 'Report catalog')

@section('content')
    <div class="page-head">
        <div>
            <h1 class="h3">Report catalog</h1>
            <div class="small text-secondary">Reports in a plan are included; the rest are monthly add-ons at the price below.</div>
        </div>
    </div>

    <form method="GET" class="card card-body report-toolbar mb-3 d-flex flex-row flex-wrap align-items-end gap-2">
        <div>
            <label for="q" class="form-label small mb-1">Search</label>
            <input id="q" type="search" name="q" value="{{ $search }}" placeholder="Name or code" class="form-control form-control-sm">
        </div>
        <button type="submit" class="btn btn-sm btn-primary">Search</button>
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table report-table table-hover mb-0">
                <thead><tr><th>Code</th><th>Report</th><th>Category</th><th>In plans</th><th class="num">Add-on price</th><th class="num">Active add-ons</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($reports as $report)
                        <tr>
                            <td class="small text-nowrap">{{ $report->code }}</td>
                            <td>{{ $report->name }}</td>
                            <td class="small">{{ $report->category }}</td>
                            <td class="small">{{ $report->plans->pluck('name')->implode(', ') ?: '—' }}</td>
                            <td class="num">@if ($report->plans->isNotEmpty() && (float) $report->addon_price == 0)<span class="text-secondary small">Included</span>@else {{ money($report->addon_price) }}/mo @endif</td>
                            <td class="num">{{ number_format($report->active_addons_count) }}</td>
                            <td>
                                <span class="badge {{ $report->is_active ? 'text-bg-primary' : 'text-bg-light border' }}">{{ $report->is_active ? 'Offered' : 'Hidden' }}</span>
                                @unless (\App\Services\Reports\ReportRunner::supports($report->code))
                                    <span class="badge text-bg-light border">Coming soon</span>
                                @endunless
                            </td>
                            <td class="text-end"><a href="{{ route('admin.reports.edit', $report) }}" class="btn btn-sm btn-link">Edit</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-secondary py-4">No reports match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @include('partials.pagination', ['paginator' => $reports, 'label' => 'reports'])
@endsection

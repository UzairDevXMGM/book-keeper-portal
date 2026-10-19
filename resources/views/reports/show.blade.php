@extends('layouts.app')

@section('title', $document->title)

@section('content')
    <a href="{{ route('reports.index') }}" class="small no-print"><i class="bi bi-arrow-left me-1"></i>Reports</a>
    <div class="page-head mt-1">
        <div>
            <h1 class="h3">{{ $document->title }}</h1>
            <div class="small text-secondary">{{ $document->subtitle }}</div>
            @if ($report->description && ! $printing)
                <div class="small mt-1 no-print"><i class="bi bi-info-circle me-1 text-secondary"></i>{{ $report->description }}</div>
            @endif
        </div>
    </div>

    @php
        // Balance sheet / trial balance are a snapshot on one date, so they get "as of" choices
        $presetOptions = $asOf
            ? ['ytd' => 'Today', 'last_month' => 'End of last month', 'last_quarter' => 'End of last quarter', 'last_fy' => 'End of last fiscal year', 'custom' => 'Custom date']
            : \App\Services\Reports\Period::PRESETS;
    @endphp
    <form method="GET" class="card card-body report-toolbar mb-3 d-flex flex-row flex-wrap align-items-end gap-2">
        <div>
            <label for="preset" class="form-label small mb-1">{{ $asOf ? 'As of' : 'Period' }}</label>
            <select id="preset" name="preset" class="form-select form-select-sm" data-preset-select>
                @foreach ($presetOptions as $value => $label)
                    <option value="{{ $value }}" @selected($period->preset === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        @unless ($asOf)
            <div data-custom-dates>
                <label for="start" class="form-label small mb-1">From</label>
                <input id="start" type="date" name="start" value="{{ $period->start->toDateString() }}" class="form-control form-control-sm">
            </div>
        @endunless
        <div data-custom-dates>
            <label for="end" class="form-label small mb-1">{{ $asOf ? 'Date' : 'To' }}</label>
            <input id="end" type="date" name="end" value="{{ $period->end->toDateString() }}" class="form-control form-control-sm">
        </div>
        @if ($comparable)
            <div>
                <label for="compare" class="form-label small mb-1">Compare with</label>
                <select id="compare" name="compare" class="form-select form-select-sm">
                    @foreach (\App\Services\Reports\Period::COMPARISONS as $value => $label)
                        <option value="{{ $value }}" @selected($period->compareMode === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        @elseif ($compareAddon)
            <div>
                <span class="form-label small mb-1 d-block">Compare with</span>
                <a href="{{ route('reports.show', $compareAddon->code) }}" class="btn btn-sm btn-light border" title="Comparing periods comes with the {{ $compareAddon->name }} add-on">
                    <i class="bi bi-lock me-1"></i>Last year · add-on
                </a>
            </div>
        @endif
        @if ($accounts->isNotEmpty())
            <div>
                <label for="account" class="form-label small mb-1">Account</label>
                <select id="account" name="account" class="form-select form-select-sm" style="max-width: 280px">
                    @if ($allAccounts)
                        <option value="">All accounts</option>
                    @endif
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}" @selected($accountId === $account->id)>{{ $account->code }} · {{ $account->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        @if (in_array('through', $offered, true) && $statementAccounts->isNotEmpty())
            <div>
                <label for="through" class="form-label small mb-1">Bank / card</label>
                <select id="through" name="through" class="form-select form-select-sm" style="max-width: 220px">
                    <option value="">All banks &amp; cards</option>
                    @foreach ($statementAccounts as $account)
                        <option value="{{ $account->id }}" @selected(($filters['through'] ?? null) === $account->id)>{{ $account->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        @if (in_array('category', $offered, true))
            <div>
                <label for="category" class="form-label small mb-1">Category</label>
                <select id="category" name="category" class="form-select form-select-sm" style="max-width: 240px">
                    <option value="">All categories</option>
                    @foreach ($costAccounts as $account)
                        <option value="{{ $account->id }}" @selected(($filters['category'] ?? null) === $account->id)>{{ $account->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        @if (in_array('group', $offered, true))
            <div>
                <label for="group" class="form-label small mb-1">Type</label>
                <select id="group" name="group" class="form-select form-select-sm">
                    <option value="">All costs</option>
                    @foreach ($groups as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['group'] ?? null) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        @if (in_array('q', $offered, true))
            <div>
                <label for="q" class="form-label small mb-1">Search</label>
                <input id="q" type="search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100" placeholder="{{ $report->code === 'BKT-10' ? 'Vendor name' : 'Description' }}" class="form-control form-control-sm" style="max-width: 200px">
            </div>
        @endif
        <button type="submit" class="btn btn-sm btn-primary">Apply</button>
        @if ($activeFilters || request()->hasAny(['preset', 'start', 'end', 'compare', 'account']))
            <a href="{{ route('reports.show', $report->code) }}" class="btn btn-sm btn-link">Reset</a>
        @endif

        <div class="ms-auto d-flex gap-2">
            <a href="{{ request()->fullUrlWithQuery(['export' => 'csv', 'page' => null, 'print' => null]) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-filetype-csv me-1"></i>CSV</a>
            {{-- The print view shows every row (no pagination) and opens the print dialog by itself --}}
            <a href="{{ request()->fullUrlWithQuery(['print' => 1, 'page' => null, 'export' => null]) }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-printer me-1"></i>Print / PDF
            </a>
        </div>
    </form>
    @if ($printing)
        <div data-auto-print hidden></div>
    @endif

    @if ($activeFilters)
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3 no-print">
            <span class="small text-secondary">Filtered by</span>
            @foreach ($activeFilters as $key => $label)
                <span class="badge rounded-pill text-bg-light border fw-normal p-2">
                    {{ $label }}
                    <a href="{{ request()->fullUrlWithQuery([$key => null, 'page' => null]) }}" class="ms-1 text-secondary" aria-label="Remove {{ $label }}"><i class="bi bi-x-lg"></i></a>
                </span>
            @endforeach
        </div>
    @endif

    @if ($document->kpis)
        <div class="row row-cols-2 row-cols-md-4 g-2 g-md-3 mb-3">
            @foreach ($document->kpis as $kpi)
                @php
                    $value = match ($kpi['format']) {
                        'pct' => pct($kpi['value']),
                        'number' => number_format((float) $kpi['value']),
                        'text' => $kpi['value'],
                        default => money($kpi['value']),
                    };
                @endphp
                <div class="col"><x-kpi :label="$kpi['label']" :value="$value" :tone="$kpi['tone']" :sub="$kpi['sub']" /></div>
            @endforeach
        </div>
    @endif

    @foreach ($document->checks as $check)
        <div @class(['small mb-2', 'text-success' => $check['ok'], 'text-danger fw-semibold' => ! $check['ok']])>
            <i @class(['bi me-1', 'bi-check-circle-fill' => $check['ok'], 'bi-exclamation-triangle-fill' => ! $check['ok']])></i>{{ $check['label'] }}
        </div>
    @endforeach

    @foreach ($document->tables as $table)
        @include('reports.partials.table', ['table' => $table])
        @if ($loop->first && $paginator)
            <div class="mb-3">
                @include('partials.pagination', ['paginator' => $paginator, 'label' => 'lines'])
                <p class="small text-secondary mt-1 mb-0"><i class="bi bi-info-circle me-1"></i>CSV and Print / PDF include every line.</p>
            </div>
        @endif
    @endforeach

    @foreach ($document->notes as $note)
        <p class="small text-secondary mb-1">{{ $note }}</p>
    @endforeach
@endsection

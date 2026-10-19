@extends('layouts.app')

@section('title', 'Dashboard')

@php
    $p = fn (string $key) => $hasPrevious ? $prev[$key] : null;   // comparison value, or null when last year has no books
    $score = $health['score'] ?? null;   // null health = the Financial Health Score add-on is not added
@endphp

@section('content')
    {{-- Page head --}}
    <div class="page-head">
        <div>
            <h1 class="h3">Dashboard</h1>
            <div class="small text-secondary">
                {{ $period->label() }}
                @if ($hasPrevious) · compared with {{ $period->compare->label() }} @endif
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2 no-print">
            <div class="btn-group btn-group-sm segmented" role="group" aria-label="Period">
                @foreach ($presets as $value => $label)
                    <a href="{{ route('dashboard', ['preset' => $value]) }}" @class(['btn', 'active' => $period->preset === $value])
                       @if ($period->preset === $value) aria-current="true" @endif>{{ $label }}</a>
                @endforeach
            </div>
            <a href="{{ route('reports.show', ['report' => 'ACC-26', 'preset' => $period->preset, 'compare' => 'prior_year']) }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-file-earmark-bar-graph me-1"></i>Profit &amp; Loss
            </a>
            <a href="{{ route('help') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-book me-1"></i>Guide</a>
        </div>
    </div>

    {{-- Get started: ticks itself off from what the business has done, and goes away when everything is done --}}
    @php
        $doneSteps = collect($onboarding)->where('done', true)->count();
        $nextStep = collect($onboarding)->firstWhere('done', false);
    @endphp
    @if ($nextStep)
        <div class="card mb-4 border-primary-subtle onboarding">
            <div class="card-body p-4">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                    <div>
                        <h2 class="h5 mb-0">Get started</h2>
                        <div class="small text-secondary">{{ $doneSteps }} of {{ count($onboarding) }} done · your books are ready after these steps</div>
                    </div>
                    <a href="{{ route('help') }}" class="small"><i class="bi bi-book me-1"></i>Read the guide</a>
                </div>
                <div class="progress progress-thin mb-3" role="progressbar" aria-label="Getting started" aria-valuenow="{{ $doneSteps }}" aria-valuemin="0" aria-valuemax="{{ count($onboarding) }}">
                    <div class="progress-bar" style="width: {{ $doneSteps / count($onboarding) * 100 }}%"></div>
                </div>
                <ol class="list-unstyled mb-0 onboarding-steps">
                    @foreach ($onboarding as $i => $step)
                        <li @class(['onboarding-step', 'is-done' => $step['done'], 'is-next' => $step['key'] === $nextStep['key']])>
                            <span class="onboarding-number" aria-hidden="true">
                                @if ($step['done']) <i class="bi bi-check-lg"></i> @else {{ $i + 1 }} @endif
                            </span>
                            <div class="flex-grow-1 min-w-0">
                                <div class="fw-semibold">{{ $step['title'] }} @if ($step['done']) <span class="visually-hidden">(done)</span> @endif</div>
                                @unless ($step['done'])
                                    <div class="small text-secondary">{{ $step['text'] }}</div>
                                @endunless
                            </div>
                            @if ($step['key'] === $nextStep['key'])
                                <a href="{{ $step['url'] }}" class="btn btn-sm btn-primary text-nowrap">{{ $step['button'] }}</a>
                            @elseif (! $step['done'])
                                <a href="{{ $step['url'] }}" class="btn btn-sm btn-link text-nowrap">{{ $step['button'] }}</a>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </div>
        </div>
    @endif

    {{-- Headline numbers --}}
    <div class="row g-3 mb-4">
        @foreach ([
            ['Revenue', money_whole($m['revenue']), 'graph-up-arrow', ['current' => $m['revenue'], 'previous' => $p('revenue')], $trend['revenue'], '#6f9a35'],
            ['Net income', money_whole($m['net_income']), 'piggy-bank', ['current' => $m['net_income'], 'previous' => $p('net_income')], $trend['net'], '#2a78d6'],
            ['Cash on hand', money_whole($m['cash']), 'bank', ['current' => $m['cash'], 'previous' => $p('cash')], $cashTrend, '#6f9a35'],
            ['Gross margin', pct($m['gross_margin']), 'percent', ['current' => $m['gross_margin'], 'previous' => $p('gross_margin'), 'points' => true], null, null],
        ] as [$label, $value, $icon, $delta, $series, $color])
            <div class="col-sm-6 col-xl-3">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="kpi-label">{{ $label }}</span>
                            <span class="kpi-icon"><i class="bi bi-{{ $icon }}"></i></span>
                        </div>
                        <div class="stat-value">{{ $value }}</div>
                        <div class="d-flex justify-content-between align-items-end gap-2">
                            <x-delta class="small" :current="$delta['current']" :previous="$delta['previous']" :points="$delta['points'] ?? false" />
                            @if ($series)
                                <x-sparkline :values="$series" :color="$color" />
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Trend + health --}}
    <div class="row g-3 mb-4">
        <div class="col-xl-8">
            <div class="card h-100">
                <div class="card-header-flex">
                    <div>
                        <h2 class="card-title-sm">Revenue, costs and net income</h2>
                        <div class="small text-secondary">Last 12 months</div>
                    </div>
                </div>
                <div class="card-body pt-0">
                    <div class="chart-box">
                        <canvas data-trend-chart='@json($trend)' role="img" aria-label="Monthly revenue, costs and net income for the last 12 months"></canvas>
                    </div>
                    <details class="mt-2 small no-print">
                        <summary class="text-secondary">View as table</summary>
                        <div class="table-responsive mt-2">
                            <table class="table table-sm report-table mb-0">
                                <thead><tr><th>Month</th><th class="num">Revenue</th><th class="num">Costs</th><th class="num">Net income</th></tr></thead>
                                <tbody>
                                    @foreach ($trend['labels'] as $i => $label)
                                        <tr>
                                            <td>{{ $label }}</td>
                                            <td class="num">{{ money($trend['revenue'][$i]) }}</td>
                                            <td class="num">{{ money($trend['costs'][$i]) }}</td>
                                            <td @class(['num', 'neg' => $trend['net'][$i] < 0])>{{ money($trend['net'][$i]) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </details>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card h-100">
                <div class="card-header-flex">
                    <h2 class="card-title-sm">Financial health</h2>
                    @if ($health)
                        <a href="{{ route('reports.show', 'PRT-07') }}" class="small">Details</a>
                    @else
                        <span class="badge rounded-pill text-bg-light border">Add-on</span>
                    @endif
                </div>
                <div class="card-body pt-0">
                    @if (! $health)
                        @include('partials.addon-lock', [
                            'reports' => $lockedAddons->only(\App\Services\Reports\ReportAccess::HEALTH)->values(),
                            'text' => 'A score out of 100 for your business, from six checks on your books.',
                            'items' => ['Liquidity', 'Profitability', 'Leverage', 'Collections', 'Cash runway', 'Data quality'],
                        ])
                    @else
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div @class(['health-ring', 'watch' => $score !== null && $score < 65 && $score >= 50, 'risk' => $score !== null && $score < 50])
                             style="--p: {{ $score ?? 0 }}" role="img" aria-label="Financial health score {{ $score ?? 'not available' }} out of 100">
                            <span>{{ $score ?? '—' }}</span>
                        </div>
                        <div>
                            <div class="h4 mb-0">{{ $health['grade'] }}</div>
                            <div class="small text-secondary">Score out of 100, from six checks on your books</div>
                        </div>
                    </div>
                    <ul class="list-unstyled mb-0">
                        @foreach ($health['components'] as $part)
                            @php $share = $part['weight'] ? $part['points'] / $part['weight'] * 100 : 0; @endphp
                            <li class="mb-2">
                                <div class="d-flex justify-content-between small">
                                    <span>{{ $part['name'] }}</span>
                                    <span class="fw-semibold">{{ rtrim(rtrim(number_format($part['points'], 1), '0'), '.') }}<span class="text-secondary fw-normal">/{{ $part['weight'] }}</span></span>
                                </div>
                                <div class="progress progress-thin" role="progressbar" aria-label="{{ $part['name'] }}" aria-valuenow="{{ round($share) }}" aria-valuemin="0" aria-valuemax="100">
                                    <div @class(['progress-bar', 'bg-warning' => $share < 60 && $share >= 40, 'bg-danger' => $share < 40]) style="width: {{ $share }}%"></div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Key metrics + alerts --}}
    <div class="row g-3 mb-4">
        <div class="col-xl-8">
            <div class="card h-100">
                <div class="card-header-flex">
                    <h2 class="card-title-sm">Key metrics</h2>
                    @if ($ratiosUnlocked)
                        <a href="{{ route('reports.show', 'ACC-27') }}" class="small">Balance sheet <i class="bi bi-arrow-right"></i></a>
                    @else
                        <span class="badge rounded-pill text-bg-light border">Add-on</span>
                    @endif
                </div>
                <div class="card-body pt-0">
                    @if (! $ratiosUnlocked)
                        @include('partials.addon-lock', [
                            'reports' => $lockedAddons->only(\App\Services\Reports\ReportAccess::RATIOS)->values(),
                            'text' => 'The numbers lenders and accountants look at, worked out from your books. Comes with either add-on.',
                            'items' => ['Working capital', 'Current ratio', 'Cash runway', 'Days to collect', 'Days to pay', 'Debt service cover'],
                        ])
                    @else
                    <div class="row row-cols-2 row-cols-md-4 g-0 metric-grid">
                        @foreach ([
                            ['EBITDA', money_whole($m['ebitda']), 'Earnings before interest, tax and depreciation'],
                            ['Working capital', money_whole($m['working_capital']), 'Current assets minus current liabilities'],
                            ['Current ratio', ratio($m['current_ratio']), 'Healthy above 1.5x'],
                            ['Cash runway', $m['runway'] !== null ? number_format($m['runway'], 1).' months' : ($m['last_entry_date'] ? 'Cash positive' : '—'), $m['runway'] !== null ? 'Burning '.money_whole($m['monthly_burn']).' a month' : 'Income covers costs'],
                            ['Receivables', money_whole($m['ar']), $m['dso'] !== null ? number_format($m['dso']).' days to collect (DSO)' : 'Owed by customers'],
                            ['Payables', money_whole($m['ap']), $m['dpo'] !== null ? number_format($m['dpo']).' days to pay (DPO)' : 'Owed to suppliers'],
                            ['Debt', money_whole($m['debt']), 'Loans and lines of credit'],
                            ['Debt service cover', ratio($m['dscr']), 'Lenders look for 1.25x or more'],
                        ] as [$label, $value, $hint])
                            <div class="col">
                                <div class="metric">
                                    <div class="kpi-label">{{ $label }}</div>
                                    <div class="metric-value">{{ $value }}</div>
                                    <div class="kpi-sub">{{ $hint }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card h-100">
                <div class="card-header-flex">
                    <h2 class="card-title-sm">Alerts <span class="badge rounded-pill text-bg-light border ms-1">{{ count($alerts) }}</span></h2>
                    <a href="{{ route('alerts.index') }}" class="small">View all</a>
                </div>
                <div class="card-body pt-0">
                    @forelse (array_slice($alerts, 0, 3) as $alert)
                        <div class="alert-item">
                            <span class="severity {{ $alert['severity'] }}">{{ $alert['severity'] }}</span>
                            <div class="small">
                                <div class="fw-semibold">{{ $alert['title'] }}</div>
                                <div class="text-secondary">{{ $alert['message'] }}</div>
                                @if ($alert['action'])
                                    <a href="{{ $alert['action']['url'] }}">{{ $alert['action']['label'] }} <i class="bi bi-arrow-right"></i></a>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="text-center py-4">
                            <i class="bi bi-check-circle fs-2 text-success"></i>
                            <p class="text-secondary small mb-0 mt-1">No alerts. Everything looks healthy.</p>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- Recent activity + top expenses --}}
    <div class="row g-3 mb-4">
        <div class="col-xl-8">
            <div class="card h-100">
                <div class="card-header-flex">
                    <h2 class="card-title-sm">Recent transactions</h2>
                    <a href="{{ route('transactions.index') }}" class="small">View all <i class="bi bi-arrow-right"></i></a>
                </div>
                <div class="table-responsive">
                    <table class="table report-table table-hover mb-0">
                        <thead><tr><th>Date</th><th>Description</th><th>Category</th><th class="num">Amount</th></tr></thead>
                        <tbody>
                            @forelse ($recent as $row)
                                @php $entry = $row['entry']; @endphp
                                <tr>
                                    <td class="text-nowrap small">{{ $entry->entry_date->format('M j, Y') }}</td>
                                    <td><a href="{{ route('journal.show', $entry) }}" class="text-body">{{ \Illuminate\Support\Str::limit($entry->description ?: 'Entry #'.$entry->entry_number, 60) }}</a></td>
                                    <td>
                                        @if ($row['category'])
                                            <span class="category-badge">{{ $row['category'] }}</span>
                                        @else
                                            <a href="{{ route('review.index') }}" class="category-badge is-missing">Needs a category</a>
                                        @endif
                                    </td>
                                    @if ($row['note'])
                                        <td class="num small text-secondary">{{ money($row['amount']) }}</td>
                                    @elseif ($row['in'] > 0)
                                        <td class="num text-success">+{{ money($row['in']) }}</td>
                                    @else
                                        <td class="num">−{{ money($row['out']) }}</td>
                                    @endif
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-secondary py-4">No transactions yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card h-100">
                <div class="card-header-flex">
                    <div>
                        <h2 class="card-title-sm">Top expenses</h2>
                        <div class="small text-secondary">Share of all costs this period</div>
                    </div>
                </div>
                <div class="card-body pt-0">
                    @forelse ($topExpenses as $row)
                        <div class="mb-3">
                            <div class="d-flex justify-content-between gap-2 small">
                                <a href="{{ route('reports.show', ['report' => 'ACC-22', 'account' => $row['account']->id, 'preset' => $period->preset]) }}" class="text-truncate text-body">{{ $row['account']->name }}</a>
                                <span class="fw-semibold text-nowrap">{{ money_whole($row['amount']) }}</span>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <div class="progress progress-thin flex-grow-1" role="progressbar" aria-label="{{ $row['account']->name }} share of costs" aria-valuenow="{{ $row['share'] }}" aria-valuemin="0" aria-valuemax="100">
                                    <div class="progress-bar" style="width: {{ $row['share'] }}%; background: #4a3aa7"></div>
                                </div>
                                <span class="small text-secondary" style="width: 3rem; text-align: right">{{ pct($row['share']) }}</span>
                            </div>
                        </div>
                    @empty
                        <p class="text-secondary small mb-0">No costs recorded in this period.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- Reports --}}
    @php
        $open = $reports->filter(fn ($r) => $r['has_access'] && $r['ready']);
        $locked = $reports->reject(fn ($r) => $r['has_access'])->count();
    @endphp
    <div class="card">
        <div class="card-header-flex">
            <h2 class="card-title-sm">Your reports</h2>
            <a href="{{ route('reports.index') }}" class="small">Report library{{ $locked ? " · {$locked} add-ons" : '' }} <i class="bi bi-arrow-right"></i></a>
        </div>
        <div class="card-body pt-0">
            <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-2">
                @foreach ($open as $item)
                    <div class="col">
                        <a href="{{ route('reports.show', $item['report']->code) }}" class="report-tile">
                            <span class="kpi-icon"><i class="bi bi-file-earmark-text"></i></span>
                            <span class="min-w-0">
                                <span class="d-block fw-semibold text-truncate">{{ $item['report']->name }}</span>
                                <span class="d-block small text-secondary text-truncate">{{ $item['report']->category }}</span>
                            </span>
                        </a>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endsection

@extends('layouts.app')

@section('title', 'Reports')

@php
    $all = $reports->flatten(1);
    $included = $all->where('included', true)->count();
    $active = $all->where('purchased', true)->count();
    $available = $all->filter(fn ($r) => ! $r['has_access'])->count();
    $show = in_array(request('show'), ['included', 'addons'], true) ? request('show') : 'all';
    // An icon per report group, so the library is easy to scan
    $icons = ['Core financial statements' => 'journal-text', 'Financial statements' => 'file-earmark-bar-graph', 'Analysis & KPIs' => 'graph-up-arrow', 'Spending analysis' => 'pie-chart'];
@endphp

@section('content')
    <div class="page-head">
        <div>
            <h1 class="h3">Reports</h1>
            <div class="small text-secondary">Built from your books every time you open them. Pick any dates, print, or save as PDF or CSV.</div>
        </div>
    </div>

    <div class="card reports-summary mb-4">
        <div class="card-body p-4 d-flex flex-wrap align-items-center gap-4">
            <div class="me-auto">
                <div class="small">Your plan</div>
                <div class="h4 mb-0">{{ $plan?->name ?? '—' }} @if ($plan)<span class="h6 fw-normal small">· {{ money($plan->price) }}/{{ $plan->billing_interval }}</span>@endif</div>
            </div>
            <div><div class="small">Included</div><div class="h4 mb-0">{{ $included }}</div></div>
            <div><div class="small">Your add-ons</div><div class="h4 mb-0">{{ $active }}</div></div>
            <div><div class="small">More to add</div><div class="h4 mb-0">{{ $available }}</div></div>
            @if ($active)
                <a href="{{ route('profile.show') }}#billing" class="btn btn-sm btn-outline-light">Manage add-ons</a>
            @endif
        </div>
    </div>

    <ul class="nav nav-pills nav-pills-sm mb-2 gap-1">
        @foreach (['all' => 'All reports', 'included' => 'In my plan', 'addons' => 'Add-ons'] as $value => $label)
            <li class="nav-item">
                <a href="{{ route('reports.index', $value === 'all' ? [] : ['show' => $value]) }}" @class(['nav-link', 'active' => $show === $value])>{{ $label }}</a>
            </li>
        @endforeach
    </ul>

    @foreach ($reports as $category => $items)
        @php
            $items = $items->filter(fn ($i) => $show === 'all' || ($show === 'included' ? $i['has_access'] : ! $i['included']));
        @endphp
        @continue($items->isEmpty())
        <h2 class="h5 mt-4 mb-3">{{ $category }}</h2>
        <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3">
            @foreach ($items as $item)
                @php $report = $item['report']; $locked = ! $item['has_access']; @endphp
                <div class="col">
                    <div @class(['card h-100 report-card', 'is-addon' => $locked])>
                        <div class="card-body d-flex flex-column">
                            <div class="d-flex align-items-start gap-3 mb-2">
                                <span class="report-icon"><i class="bi bi-{{ $locked ? 'lock' : ($icons[$category] ?? 'file-earmark-text') }}"></i></span>
                                <div class="min-w-0 flex-grow-1">
                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                        <h3 class="h6 mb-0">{{ $report->name }}</h3>
                                        @if ($item['included'])
                                            <span class="badge rounded-pill text-bg-primary">Included</span>
                                        @elseif ($item['purchased'])
                                            <span class="badge rounded-pill text-bg-dark">Add-on active</span>
                                        @else
                                            <span class="badge rounded-pill text-bg-light border">{{ money($report->addon_price) }}/mo</span>
                                        @endif
                                    </div>
                                    <p class="small text-secondary mb-0 mt-1">{{ $report->description }}</p>
                                </div>
                            </div>
                            <div class="mt-auto pt-2 text-end">
                                @if ($item['has_access'] && $item['ready'])
                                    <a href="{{ route('reports.show', $report->code) }}" class="btn btn-sm btn-primary">Open report</a>
                                @elseif ($item['has_access'])
                                    <span class="btn btn-sm btn-light border disabled">Coming soon</span>
                                @else
                                    <a href="{{ route('reports.show', $report->code) }}" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-eye me-1"></i>Preview &amp; add
                                    </a>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endforeach
@endsection

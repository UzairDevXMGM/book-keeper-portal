@extends('layouts.app')

@section('title', 'Reports')

@section('content')
    <div class="page-head">
        <div>
            <h1 class="h3">Reports</h1>
            <div class="small text-secondary">
                {{ $plan?->name ?? 'Your' }} plan reports are included. Other reports are monthly add-ons.
            </div>
        </div>
    </div>

    @foreach ($reports as $category => $items)
        <h2 class="h5 mt-4 mb-3">{{ $category }}</h2>
        <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3">
            @foreach ($items as $item)
                @php $report = $item['report']; @endphp
                <div class="col">
                    <div class="card h-100">
                        <div class="card-body d-flex flex-column">
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                                <h3 class="h6 mb-0">{{ $report->name }}</h3>
                                @if ($item['included'])
                                    <span class="badge rounded-pill text-bg-primary">Included</span>
                                @elseif ($item['purchased'])
                                    <span class="badge rounded-pill text-bg-dark">Add-on active</span>
                                @else
                                    <span class="badge rounded-pill text-bg-light border">${{ number_format($report->addon_price, 0) }}/mo</span>
                                @endif
                            </div>
                            <p class="small text-secondary mb-3">{{ $report->description }}</p>
                            <div class="mt-auto d-flex align-items-center justify-content-between">
                                <span></span>
                                @if ($item['has_access'] && $item['ready'])
                                    <a href="{{ route('reports.show', $report->code) }}" class="btn btn-sm btn-primary">Open report</a>
                                @elseif ($item['has_access'])
                                    <span class="btn btn-sm btn-light border disabled">Coming soon</span>
                                @else
                                    <a href="{{ route('reports.show', $report->code) }}" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-plus-lg me-1"></i>Get add-on
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

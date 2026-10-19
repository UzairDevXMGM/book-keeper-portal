{{--
    Card body for a dashboard piece that belongs to an add-on the business has not added yet.
    @include('partials.addon-lock', ['reports' => [$report, ...], 'text' => 'What it shows', 'items' => ['Current ratio', ...]])
--}}
<div class="text-center py-3 px-2">
    <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-light border mb-2" style="width: 44px; height: 44px">
        <i class="bi bi-lock fs-5 text-secondary"></i>
    </span>
    <p class="small text-secondary mb-2">{{ $text }}</p>
    @if (! empty($items))
        <div class="d-flex flex-wrap justify-content-center gap-1 mb-3">
            @foreach ($items as $item)
                <span class="badge rounded-pill text-bg-light border fw-normal">{{ $item }}</span>
            @endforeach
        </div>
    @endif
    <div class="d-flex flex-wrap justify-content-center gap-2">
        @foreach ($reports as $report)
            <a href="{{ route('reports.show', $report->code) }}" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-plus-lg me-1"></i>{{ $report->name }} · {{ money($report->addon_price) }}/mo
            </a>
        @endforeach
    </div>
</div>

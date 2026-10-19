{{--
    Pagination bar: "Showing 1–50 of 639 lines", an optional rows-per-page picker and the page links.
    @include('partials.pagination', ['paginator' => $lines, 'label' => 'lines', 'perPageOptions' => [25, 50, 100]])
--}}
@php
    $perPageOptions = $perPageOptions ?? [];
    $label = $label ?? 'rows';
@endphp

@if ($paginator->total() > 0)
    <div class="pagination-bar">
        <div class="small text-secondary">
            Showing <strong>{{ number_format($paginator->firstItem()) }}–{{ number_format($paginator->lastItem()) }}</strong>
            of <strong>{{ number_format($paginator->total()) }}</strong> {{ $label }}
        </div>

        <div class="d-flex flex-wrap align-items-center gap-3">
            @if ($perPageOptions && $paginator->total() > min($perPageOptions))
                <form method="GET" class="d-flex align-items-center gap-2">
                    @foreach (request()->except(['page', 'per_page']) as $key => $value)
                        @if (is_scalar($value))
                            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                        @endif
                    @endforeach
                    <label for="per_page_{{ $paginator->getPageName() }}" class="small text-secondary text-nowrap">Rows per page</label>
                    <select id="per_page_{{ $paginator->getPageName() }}" name="per_page" class="form-select form-select-sm w-auto" data-autosubmit>
                        @foreach ($perPageOptions as $option)
                            <option value="{{ $option }}" @selected($paginator->perPage() === $option)>{{ $option }}</option>
                        @endforeach
                    </select>
                </form>
            @endif

            {{ $paginator->onEachSide(1)->links('partials.pagination-links') }}
        </div>
    </div>
@endif

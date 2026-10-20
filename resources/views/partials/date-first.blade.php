{{--
    "Pick the dates first": quick choices and a From / To form, shown before a list or ledger loads.
    @include('partials.date-first', ['action' => url, 'keep' => ['view' => 'accountant'], 'presets' => [...], 'what' => 'transactions'])
--}}
@php($keep = array_filter($keep ?? [], fn ($v) => $v !== null && $v !== ''))
<div class="card date-first">
    <div class="card-body p-4">
        <div class="d-flex align-items-center gap-3 mb-3">
            <span class="kpi-icon" style="width: 2.75rem; height: 2.75rem; font-size: 1.25rem"><i class="bi bi-calendar3"></i></span>
            <div>
                <h2 class="h5 mb-0">Which dates do you want to see?</h2>
                <div class="small text-secondary">Pick a period and we show your {{ $what }} for it.</div>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2 mb-4">
            @foreach ($presets as $value => $label)
                <a href="{{ $action.'?'.http_build_query($keep + ['preset' => $value]) }}" class="btn btn-outline-primary">{{ $label }}</a>
            @endforeach
        </div>
        <form method="GET" action="{{ $action }}" class="d-flex flex-wrap align-items-end gap-2">
            @foreach ($keep as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <input type="hidden" name="preset" value="custom">
            <div>
                <label for="df-start" class="form-label small mb-1">From</label>
                <input id="df-start" type="date" name="start" class="form-control" required>
            </div>
            <div>
                <label for="df-end" class="form-label small mb-1">To</label>
                <input id="df-end" type="date" name="end" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary">Show</button>
        </form>
    </div>
</div>

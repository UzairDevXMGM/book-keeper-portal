{{--
    Change against a comparison value: arrow + percentage (or percentage points), coloured good / bad.
    `inverse` = a rise is bad (costs). Identity is never colour-only: there is always an arrow and the number.
--}}
@props(['current', 'previous', 'inverse' => false, 'points' => false, 'label' => 'vs last year'])

@php
    $change = null;
    if ($current !== null && $previous !== null) {
        if ($points) {
            $change = round($current - $previous, 1);
        } elseif (abs($previous) >= 0.005) {
            $change = round(($current - $previous) / abs($previous) * 100, 1);
        }
    }
    $good = $change !== null && ($inverse ? $change < 0 : $change > 0);
@endphp

<span {{ $attributes->class(['delta-wrap']) }}>
    @if ($change === null)
        <span class="text-secondary">No data {{ $label }}</span>
    @else
        <span @class(['delta', 'is-good' => $change != 0 && $good, 'is-bad' => $change != 0 && ! $good])>
            <i class="bi bi-arrow-{{ $change > 0 ? 'up' : ($change < 0 ? 'down' : 'right') }}-short" aria-hidden="true"></i>
            <span class="visually-hidden">{{ $change > 0 ? 'Up' : ($change < 0 ? 'Down' : 'No change') }}</span>
            {{ number_format(abs($change), 1) }}{{ $points ? ' pts' : '%' }}
        </span>
        <span class="text-secondary">{{ $label }}</span>
    @endif
</span>

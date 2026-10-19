{{-- Tiny trend line (no axes, no JS). Decorative: the numbers it summarises are shown next to it. --}}
@props(['values', 'color' => '#6f9a35'])

@php
    $values = array_values(array_map('floatval', $values));
    $count = count($values);
    $width = 120;
    $height = 36;
    $pad = 3;
    $min = $count ? min($values) : 0;
    $range = ($count ? max($values) : 0) - $min ?: 1;
    $points = collect($values)->map(fn ($v, $i) => round($count > 1 ? $i * $width / ($count - 1) : 0, 1).','.round($height - $pad - ($v - $min) / $range * ($height - 2 * $pad), 1))->implode(' ');
@endphp

@if ($count > 1)
    <svg {{ $attributes->class(['sparkline']) }} viewBox="0 0 {{ $width }} {{ $height }}" preserveAspectRatio="none" aria-hidden="true" focusable="false">
        <polygon points="0,{{ $height }} {{ $points }} {{ $width }},{{ $height }}" fill="{{ $color }}" fill-opacity=".1"></polygon>
        <polyline points="{{ $points }}" fill="none" stroke="{{ $color }}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"></polyline>
    </svg>
@endif

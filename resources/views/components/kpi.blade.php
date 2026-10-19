@props(['label', 'value', 'sub' => null, 'tone' => null, 'icon' => null])

<div {{ $attributes->class(['kpi-card', "tone-{$tone}" => $tone]) }}>
    <div class="d-flex justify-content-between align-items-start gap-2">
        <div class="kpi-label">{{ $label }}</div>
        @if ($icon)
            <span class="kpi-icon" aria-hidden="true"><i class="bi bi-{{ $icon }}"></i></span>
        @endif
    </div>
    <div class="kpi-value">{{ $value }}</div>
    @if ($sub)
        <div class="kpi-sub">{{ $sub }}</div>
    @endif
</div>

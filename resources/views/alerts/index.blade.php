@extends('layouts.app')

@section('title', 'Alerts')

@section('content')
    <div class="page-head">
        <div>
            <h1 class="h3">Alerts</h1>
            <div class="small text-secondary">Checked against your books every time you open this page (fiscal year to date).</div>
        </div>
    </div>

    @forelse ($alerts as $alert)
        <div class="card mb-2">
            <div class="card-body d-flex flex-wrap align-items-start gap-3">
                <span class="severity {{ $alert['severity'] }}">
                    <i @class(['bi', 'bi-exclamation-octagon' => $alert['severity'] === 'high', 'bi-exclamation-triangle' => $alert['severity'] === 'warning', 'bi-info-circle' => $alert['severity'] === 'info'])></i>
                    {{ $alert['severity'] }}
                </span>
                <div class="me-auto">
                    <div class="fw-semibold">{{ $alert['title'] }}</div>
                    <div class="text-secondary small">{{ $alert['message'] }}</div>
                </div>
                @if ($alert['action'])
                    <a href="{{ $alert['action']['url'] }}" class="btn btn-sm btn-outline-secondary">{{ $alert['action']['label'] }}</a>
                @endif
            </div>
        </div>
    @empty
        <div class="card">
            <div class="card-body p-4 text-center">
                <i class="bi bi-check-circle display-6 text-success"></i>
                <p class="mt-2 mb-0">No alerts. Your books look healthy.</p>
            </div>
        </div>
    @endforelse
@endsection

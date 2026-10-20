@extends('layouts.app')

@section('title', $report->name)

@section('content')
    <a href="{{ route('reports.index') }}" class="small"><i class="bi bi-arrow-left me-1"></i>Reports</a>
    <div class="page-head mt-1">
        <div>
            <h1 class="h3">{{ $report->name }}</h1>
            @if ($report->description)
                <div class="small text-secondary">{{ $report->description }}</div>
            @endif
        </div>
    </div>

    @include('partials.date-first', ['action' => $action, 'keep' => $keep, 'presets' => $presets, 'what' => strtolower($report->name)])
@endsection

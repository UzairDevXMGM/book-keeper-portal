@extends('layouts.app')

@section('title', $report->name)

@section('content')
    <a href="{{ route('reports.index') }}" class="small"><i class="bi bi-arrow-left me-1"></i>Reports</a>
    <div class="card mt-2" style="max-width: 640px">
        <div class="card-body p-4">
            <i class="bi bi-hourglass-split display-6 text-secondary"></i>
            <h1 class="h4 mt-3 mb-1">{{ $report->name }}</h1>
            <p class="text-secondary mb-0">{{ $report->description }} This report is being built and will appear here automatically.</p>
        </div>
    </div>
@endsection

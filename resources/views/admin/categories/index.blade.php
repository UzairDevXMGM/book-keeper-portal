@extends('layouts.app')

@section('title', 'Categories')

@php
    $names = $accounts->flatten()->pluck('name', 'code');
    $systemLabels = \App\Http\Controllers\Admin\CategoryTypeController::SYSTEM_TARGETS;
    $directions = ['out' => 'Money out', 'in' => 'Money in', 'any' => 'Both ways'];
@endphp

@section('content')
    <div class="page-head">
        <div>
            <h1 class="h3">Categories</h1>
            <div class="small text-secondary">
                Merchant keywords belong to a category, and the category decides the account. Walmart → Meals &amp; Entertainment, gas stations → Fuel.
            </div>
        </div>
        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#createCategoryModal">
            <i class="bi bi-plus-lg me-1"></i>Add category
        </button>
    </div>

    <form method="GET" class="card card-body report-toolbar mb-3 d-flex flex-row flex-wrap align-items-end gap-2">
        <div>
            <label for="q" class="form-label small mb-1">Search</label>
            <input id="q" type="search" name="q" value="{{ $search }}" placeholder="Category or merchant keyword" class="form-control form-control-sm" style="min-width: 260px">
        </div>
        <button type="submit" class="btn btn-sm btn-primary">Search</button>
        @if ($search !== '')
            <a href="{{ route('admin.categories.index') }}" class="btn btn-sm btn-link">Clear</a>
        @endif
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table report-table table-hover mb-0">
                <thead>
                    <tr><th>Category</th><th>Direction</th><th>Posts to</th><th class="num">Keywords</th><th class="num">Industry accounts</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                    @forelse ($types as $type)
                        <tr>
                            <td>
                                <a href="{{ route('admin.categories.edit', $type) }}" class="fw-semibold">{{ $type->name }}</a>
                                @if ($type->description)
                                    <div class="small text-secondary">{{ \Illuminate\Support\Str::limit($type->description, 90) }}</div>
                                @endif
                            </td>
                            <td class="small">{{ $directions[$type->direction] ?? $type->direction }}</td>
                            <td class="small">
                                @if ($type->system_key)
                                    {{ $systemLabels[$type->system_key] ?? $type->system_key }}
                                @else
                                    {{ $type->account_code }} · {{ $names[$type->account_code] ?? 'missing account' }}
                                @endif
                                @if ($type->needs_review)
                                    <span class="badge text-bg-light border ms-1">always review</span>
                                @endif
                            </td>
                            <td class="num">{{ number_format($type->keywords_count) }}</td>
                            <td class="num">{{ $type->industry_accounts_count ?: '—' }}</td>
                            <td><span class="badge {{ $type->is_active ? 'text-bg-primary' : 'text-bg-light border' }}">{{ $type->is_active ? 'On' : 'Off' }}</span></td>
                            <td class="text-end"><a href="{{ route('admin.categories.edit', $type) }}" class="btn btn-sm btn-link">Edit</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-secondary py-4">No categories match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Add category --}}
    <div class="modal fade" id="createCategoryModal" tabindex="-1" aria-labelledby="createCategoryTitle" aria-hidden="true"
         @if ($errors->createType->any()) data-open-on-load @endif>
        <div class="modal-dialog">
            <form method="POST" action="{{ route('admin.categories.store') }}" class="modal-content">
                @csrf
                <div class="modal-header">
                    <h2 class="modal-title h5" id="createCategoryTitle">Add category</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2">
                        <div class="col-8">
                            <div class="form-floating">
                                <input id="new_name" name="name" value="{{ old('name') }}" placeholder="Name" class="form-control @error('name', 'createType') is-invalid @enderror" required>
                                <label for="new_name">Name (e.g. Truck Wash)</label>
                                @error('name', 'createType') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="form-floating">
                                <select id="new_direction" name="direction" class="form-select">
                                    @foreach ($directions as $value => $label)
                                        <option value="{{ $value }}" @selected(old('direction', 'out') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <label for="new_direction">Direction</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-floating">
                                @include('admin.categories.partials.target', ['id' => 'new_target', 'selected' => old('target', '6580'), 'bag' => 'createType'])
                                <label for="new_target">Posts to</label>
                                @error('target', 'createType') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-floating">
                                <input id="new_description" name="description" value="{{ old('description') }}" placeholder="Description" class="form-control">
                                <label for="new_description">Description (optional)</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add category</button>
                </div>
            </form>
        </div>
    </div>
@endsection

@extends('layouts.app')

@section('title', $type->name)

@php
    $directions = ['out' => 'Money out', 'in' => 'Money in', 'any' => 'Both ways'];
    $matchTypes = ['contains' => 'Description contains it', 'word' => 'Whole word only'];
    $appliesTo = ['any' => 'Bank and card', 'bank' => 'Bank only', 'credit_card' => 'Card only'];
    $target = old('target', $type->system_key ?? $type->account_code);
@endphp

@section('content')
    <a href="{{ route('admin.categories.index') }}" class="small"><i class="bi bi-arrow-left me-1"></i>Categories</a>
    <div class="page-head mt-1">
        <div>
            <h1 class="h3">{{ $type->name }}</h1>
            <div class="small text-secondary">{{ $type->description ?: 'Merchant keywords below are categorized as '.$type->name.'.' }}</div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-5">
            <form method="POST" action="{{ route('admin.categories.update', $type) }}" class="card" novalidate>
                @csrf
                @method('PATCH')
                <div class="card-header bg-white fw-semibold">Category</div>
                <div class="card-body">
                    <div class="row g-2">
                        <div class="col-12">
                            <div class="form-floating">
                                <input id="name" name="name" value="{{ old('name', $type->name) }}" placeholder="Name" class="form-control @error('name') is-invalid @enderror" required>
                                <label for="name">Name</label>
                                @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-floating">
                                @include('admin.categories.partials.target', ['id' => 'target', 'selected' => $target])
                                <label for="target">Posts to (every company)</label>
                                @error('target') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                        <div class="col-12 col-xl-6">
                            <div class="form-floating">
                                <select id="direction" name="direction" class="form-select">
                                    @foreach ($directions as $value => $label)
                                        <option value="{{ $value }}" @selected(old('direction', $type->direction) === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <label for="direction">Direction</label>
                            </div>
                        </div>
                        <div class="col-12 col-xl-6 d-flex flex-column justify-content-center gap-1">
                            <div class="form-check form-switch">
                                <input type="hidden" name="is_active" value="0">
                                <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" @checked(old('is_active', $type->is_active))>
                                <label class="form-check-label small" for="is_active">Category on</label>
                            </div>
                            <div class="form-check form-switch">
                                <input type="hidden" name="needs_review" value="0">
                                <input class="form-check-input" type="checkbox" role="switch" id="needs_review" name="needs_review" value="1" @checked(old('needs_review', $type->needs_review))>
                                <label class="form-check-label small" for="needs_review">Always send to review</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-floating">
                                <textarea id="description" name="description" placeholder="Description" class="form-control" style="height: 80px">{{ old('description', $type->description) }}</textarea>
                                <label for="description">Description</label>
                            </div>
                        </div>
                    </div>
                    <p class="small text-secondary mt-2 mb-0">Changes apply to statements uploaded from now on. Lines already in the books stay where they are.</p>
                </div>
                <div class="card-footer bg-white text-end">
                    <button type="submit" class="btn btn-primary">Save category</button>
                </div>
            </form>

            {{-- Industry accounts --}}
            <div class="card mt-3">
                <div class="card-header bg-white fw-semibold">Industry accounts</div>
                <div class="card-body pb-2">
                    <p class="small text-secondary">For these industries the category posts to the industry's own account instead (e.g. Trucking: Fuel → 5215 Fuel - Trucks).</p>
                    @if ($type->system_key)
                        <p class="small mb-0">This category posts to a system account, so industries do not change it.</p>
                    @else
                        <ul class="list-group list-group-flush small mb-3">
                            @forelse ($type->industryAccounts->sortBy('industry') as $link)
                                <li class="list-group-item px-0 d-flex justify-content-between align-items-center gap-2">
                                    <span>
                                        {{ $link->industry }}
                                        <span class="text-secondary">→ {{ $link->account_code }} · {{ $industryNames["{$link->industry}|{$link->account_code}"]->name ?? 'account' }}</span>
                                    </span>
                                    <form method="POST" action="{{ route('admin.categories.industries.destroy', [$type, $link]) }}" data-confirm="{{ $link->industry }} will use the category's default account again." data-confirm-title="Remove industry account?" data-confirm-button="Remove" data-confirm-tone="danger">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-link text-danger p-0" aria-label="Remove {{ $link->industry }}"><i class="bi bi-x-lg"></i></button>
                                    </form>
                                </li>
                            @empty
                                <li class="list-group-item px-0 text-secondary">None: every industry uses {{ $type->account_code }}.</li>
                            @endforelse
                        </ul>
                        <form method="POST" action="{{ route('admin.categories.industries.store', $type) }}" class="row g-2" novalidate>
                            @csrf
                            <div class="col-12">
                                <select name="industry" class="form-select form-select-sm @error('industry', 'industry') is-invalid @enderror" aria-label="Industry" required>
                                    <option value="">Industry...</option>
                                    @foreach ($industries as $sector => $list)
                                        <optgroup label="{{ $sector }}">
                                            @foreach ($list as $industry)
                                                <option value="{{ $industry }}" @selected(old('industry') === $industry)>{{ $industry }}</option>
                                            @endforeach
                                        </optgroup>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-7">
                                <input name="account_code" value="{{ old('account_code') }}" placeholder="Account code (e.g. 5215)" class="form-control form-control-sm @error('account_code', 'industry') is-invalid @enderror" aria-label="Account code" required>
                            </div>
                            <div class="col-5"><button type="submit" class="btn btn-sm btn-outline-primary w-100">Set account</button></div>
                            @error('account_code', 'industry') <div class="col-12 small text-danger">{{ $message }}</div> @enderror
                        </form>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card">
                <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span class="fw-semibold">Merchant keywords <span class="text-secondary fw-normal">({{ number_format($keywords->total()) }})</span></span>
                    <form method="GET" class="d-flex gap-1">
                        <input type="search" name="q" value="{{ $search }}" placeholder="Search keywords" class="form-control form-control-sm" aria-label="Search keywords">
                        <button type="submit" class="btn btn-sm btn-light border">Search</button>
                    </form>
                </div>

                <form method="POST" action="{{ route('admin.categories.keywords.store', $type) }}" class="card-body border-bottom row g-2 align-items-end" novalidate>
                    @csrf
                    <div class="col-12">
                        <label for="keyword" class="form-label small mb-1">Add a merchant keyword</label>
                        <input id="keyword" name="keyword" value="{{ old('keyword') }}" placeholder="e.g. WAL-MART or LOVE'S" class="form-control form-control-sm @error('keyword', 'keyword') is-invalid @enderror" required>
                        @error('keyword', 'keyword') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-6 col-xl-5">
                        <select name="match_type" class="form-select form-select-sm" aria-label="How it matches">
                            @foreach ($matchTypes as $value => $label)
                                <option value="{{ $value }}" @selected(old('match_type', 'contains') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-xl-4">
                        <select name="applies_to" class="form-select form-select-sm" aria-label="Statements">
                            @foreach ($appliesTo as $value => $label)
                                <option value="{{ $value }}" @selected(old('applies_to', 'any') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 col-xl-3"><button type="submit" class="btn btn-sm btn-primary w-100">Add keyword</button></div>
                    <div class="col-12 small text-secondary">A keyword belongs to one category; adding it here moves it from any other category.</div>
                </form>

                <div class="table-responsive">
                    <table class="table report-table table-hover mb-0">
                        <thead><tr><th>Keyword</th><th>Merchant</th><th>Matches</th><th>Statements</th><th></th></tr></thead>
                        <tbody>
                            @forelse ($keywords as $rule)
                                <tr>
                                    <td class="fw-semibold small">{{ $rule->keyword }}</td>
                                    <td class="small">{{ $rule->name ?? '—' }}</td>
                                    <td class="small text-secondary">{{ $rule->source === 'bank_category' ? 'Bank category' : ($matchTypes[$rule->match_type] ?? $rule->match_type) }}</td>
                                    <td class="small text-secondary">{{ $appliesTo[$rule->applies_to] ?? $rule->applies_to }}</td>
                                    <td class="text-end">
                                        <form method="POST" action="{{ route('admin.categories.keywords.destroy', [$type, $rule]) }}" data-confirm="New uploads with “{{ $rule->keyword }}” will no longer be put in {{ $type->name }}." data-confirm-title="Remove this keyword?" data-confirm-button="Remove keyword" data-confirm-tone="danger">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-link text-danger" aria-label="Remove {{ $rule->keyword }}"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-secondary py-4">No keywords{{ $search !== '' ? ' match' : ' yet' }}.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @include('partials.pagination', ['paginator' => $keywords, 'label' => 'keywords'])
        </div>
    </div>
@endsection

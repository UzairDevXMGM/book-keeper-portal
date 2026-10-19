@extends('layouts.app')

@section('title', 'Journal entry')

@php
    $typeLabels = \App\Http\Controllers\AccountController::TYPE_LABELS;
    $grouped = $accounts->groupBy('type');
    $oldLines = old('lines', [[], []]);
@endphp

@section('content')
    <div class="page-head">
        <div>
            <h1 class="h3">Journal entry</h1>
            <div class="small text-secondary">For adjustments, opening balances and anything that is not on a statement. Debits must equal credits.</div>
        </div>
    </div>

    <form method="POST" action="{{ route('journal.store') }}" class="card mb-4" data-journal-form novalidate>
        @csrf
        <div class="card-body">
            <div class="row g-2 mb-3">
                <div class="col-sm-6 col-lg-2">
                    <div class="form-floating">
                        <input id="entry_date" type="date" name="entry_date" value="{{ old('entry_date', now()->toDateString()) }}" placeholder="Date"
                               class="form-control @error('entry_date') is-invalid @enderror" required>
                        <label for="entry_date">Date</label>
                        @error('entry_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <div class="form-floating">
                        <select id="source" name="source" class="form-select">
                            @foreach (['manual' => 'General', 'adjustment' => 'Adjustment', 'opening_balance' => 'Opening balance'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('source', 'manual') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <label for="source">Type</label>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="form-floating">
                        <input id="description" name="description" value="{{ old('description') }}" placeholder="Description" maxlength="255"
                               class="form-control @error('description') is-invalid @enderror" required>
                        <label for="description">Description</label>
                        @error('description') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
                <div class="col-lg-2">
                    <div class="form-floating">
                        <input id="reference" name="reference" value="{{ old('reference') }}" placeholder="Reference" maxlength="100" class="form-control">
                        <label for="reference">Reference <span class="fw-normal">(optional)</span></label>
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table journal-lines align-middle mb-2">
                    <thead>
                        <tr class="small text-secondary">
                            <th style="min-width: 240px">Account</th><th style="min-width: 180px">Line description</th>
                            <th style="width: 150px" class="text-end">Debit</th><th style="width: 150px" class="text-end">Credit</th><th style="width: 40px"></th>
                        </tr>
                    </thead>
                    <tbody data-lines>
                        @foreach ($oldLines as $line)
                            @include('journal.partials.line', ['line' => $line])
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="fw-semibold">
                            <td><button type="button" class="btn btn-sm btn-outline-secondary" data-add-line><i class="bi bi-plus-lg me-1"></i>Add line</button></td>
                            <td class="text-end"><span data-difference></span></td>
                            <td class="text-end" data-total-debit>$0.00</td>
                            <td class="text-end" data-total-credit>$0.00</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <template data-line-template>@include('journal.partials.line', ['line' => []])</template>
        </div>
        <div class="card-footer bg-white d-flex justify-content-end gap-2">
            <a href="{{ route('transactions.index') }}" class="btn btn-light">Cancel</a>
            <button type="submit" class="btn btn-primary" data-post disabled>
                <span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true"></span>
                Post entry
            </button>
        </div>
    </form>

    @if ($recent->isNotEmpty())
        <h2 class="h5 mb-3">Recent manual entries</h2>
        <div class="card">
            <div class="table-responsive">
                <table class="table report-table table-hover mb-0">
                    <thead><tr><th>Date</th><th>Entry</th><th>Description</th><th class="num">Amount</th></tr></thead>
                    <tbody>
                        @foreach ($recent as $entry)
                            <tr>
                                <td class="text-nowrap">{{ $entry->entry_date->format('M j, Y') }}</td>
                                <td><a href="{{ route('journal.show', $entry) }}">#{{ $entry->entry_number }}</a></td>
                                <td>{{ $entry->description }}</td>
                                <td class="num">{{ money($entry->lines->sum('debit')) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection

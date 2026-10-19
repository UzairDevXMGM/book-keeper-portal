@extends('layouts.app')

@section('title', 'Chart of accounts')

@section('content')
    <div class="page-head">
        <div>
            <h1 class="h3">Chart of accounts</h1>
            <div class="small text-secondary">
                Balance sheet balances are as of today; income and expense balances are since your fiscal year started on {{ $fyStart->format('M j, Y') }}.
            </div>
        </div>
        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#createAccountModal">
            <i class="bi bi-plus-lg me-1"></i>Add account
        </button>
    </div>

    <div class="card card-body report-toolbar mb-3 d-flex flex-row flex-wrap align-items-center gap-3">
        <div class="flex-grow-1" style="max-width: 360px">
            <label for="account-search" class="visually-hidden">Search accounts</label>
            <input id="account-search" type="search" class="form-control form-control-sm" placeholder="Search by name or code" data-account-search>
        </div>
        <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" role="switch" id="only-used" data-account-only-used @checked(count($usedIds) > 0)>
            <label class="form-check-label small" for="only-used">Only accounts you use</label>
        </div>
        <span class="small text-secondary ms-auto" data-account-count></span>
    </div>

    @foreach ($groups as $type => $accounts)
        <div class="card mb-3" data-account-group>
            <div class="card-header bg-white d-flex justify-content-between">
                <span class="fw-semibold">{{ $typeLabels[$type] ?? $type }}</span>
                <span class="small text-secondary"><span data-group-count>{{ $accounts->count() }}</span> accounts</span>
            </div>
            <div class="table-responsive">
                <table class="table report-table table-hover mb-0">
                    <thead><tr><th style="width: 70px">Code</th><th>Name</th><th class="d-none d-md-table-cell">Section</th><th class="num">Balance</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($accounts as $account)
                            @php $balance = $balances[$account->id] ?? 0; @endphp
                            <tr @class(['text-secondary' => ! $account->is_active]) data-account-row
                                data-search="{{ strtolower($account->code.' '.$account->name) }}"
                                data-used="{{ isset($usedIds[$account->id]) || $account->system_key || $account->created_at?->gt($company->created_at->addMinutes(10)) ? 1 : 0 }}">
                                <td>{{ $account->code }}</td>
                                <td>
                                    <a href="{{ route('reports.show', ['report' => 'ACC-22', 'account' => $account->id]) }}">{{ $account->name }}</a>
                                    @if ($account->system_key)
                                        <span class="badge text-bg-light border ms-1" title="Used by the system">System</span>
                                    @endif
                                    @if (! $account->is_active)
                                        <span class="badge text-bg-secondary ms-1">Hidden</span>
                                    @endif
                                </td>
                                <td class="small d-none d-md-table-cell">{{ $account->section }}</td>
                                <td @class(['num', 'neg' => $balance < 0])>{{ money($balance, true) }}</td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-link py-0" data-bs-toggle="modal" data-bs-target="#editAccountModal" data-edit-account
                                            data-action="{{ route('accounts.update', $account) }}" data-code="{{ $account->code }}" data-name="{{ $account->name }}"
                                            data-active="{{ $account->is_active ? 1 : 0 }}" data-system="{{ $account->system_key ? 1 : 0 }}">
                                        Edit<span class="visually-hidden"> {{ $account->name }}</span>
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach

    {{-- Add account --}}
    <div class="modal fade" id="createAccountModal" tabindex="-1" aria-labelledby="createAccountTitle" aria-hidden="true"
         @if ($errors->createAccount->any() || request()->boolean('new')) data-open-on-load @endif>
        <div class="modal-dialog">
            <form method="POST" action="{{ route('accounts.store') }}" class="modal-content">
                @csrf
                <div class="modal-header">
                    <h2 class="modal-title h5" id="createAccountTitle">Add account</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    @php $kind = old('kind', request('kind', 'regular')); @endphp
                    <div class="row g-2">
                        <div class="col-12">
                            <div class="form-floating">
                                <select id="kind" name="kind" class="form-select" data-account-kind>
                                    <option value="regular" @selected($kind === 'regular')>A regular account (income, expense, asset ...)</option>
                                    <option value="bank" @selected($kind === 'bank')>A bank account (checking or savings)</option>
                                    <option value="card" @selected($kind === 'card')>A credit card</option>
                                </select>
                                <label for="kind">This account is</label>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="form-floating">
                                <input id="code" name="code" value="{{ old('code') }}" placeholder="Code" required
                                       class="form-control @error('code', 'createAccount') is-invalid @enderror">
                                <label for="code">Code</label>
                                @error('code', 'createAccount') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                        <div class="col-8">
                            <div class="form-floating">
                                <input id="name" name="name" value="{{ old('name') }}" placeholder="Name" required
                                       class="form-control @error('name', 'createAccount') is-invalid @enderror">
                                <label for="name">Account name</label>
                                @error('name', 'createAccount') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                        <div class="col-6" data-regular-only>
                            <div class="form-floating">
                                <select id="type" name="type" class="form-select @error('type', 'createAccount') is-invalid @enderror" required>
                                    @foreach ($typeLabels as $value => $label)
                                        <option value="{{ $value }}" @selected(old('type', 'expense') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <label for="type">Type</label>
                            </div>
                        </div>
                        <div class="col-6" data-regular-only>
                            <div class="form-floating">
                                <select id="section" name="section" class="form-select">
                                    <option value="">—</option>
                                    @foreach ($sections as $section)
                                        <option value="{{ $section }}" @selected(old('section') === $section)>{{ $section }}</option>
                                    @endforeach
                                </select>
                                <label for="section">Statement section</label>
                            </div>
                        </div>
                    </div>
                    <p class="small text-secondary mt-2 mb-0" data-regular-only>Pick the section where the account should appear on the balance sheet or P&amp;L.</p>
                    <p class="small text-secondary mt-2 mb-0" data-statement-only hidden>
                        Add one account per bank account or card (for example "Chase Checking 8350"), so each statement uploads and reconciles on its own.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add account</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Edit account (filled by app.js from the clicked row) --}}
    <div class="modal fade" id="editAccountModal" tabindex="-1" aria-labelledby="editAccountTitle" aria-hidden="true">
        <div class="modal-dialog">
            <form method="POST" id="editAccountForm" class="modal-content">
                @csrf
                @method('PATCH')
                <div class="modal-header">
                    <h2 class="modal-title h5" id="editAccountTitle">Edit account <span class="text-secondary" data-account-code></span></h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="form-floating mb-3">
                        <input id="edit_name" name="name" placeholder="Name" class="form-control" required maxlength="200">
                        <label for="edit_name">Account name</label>
                    </div>
                    <input type="hidden" name="is_active" value="0">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="edit_active" name="is_active" value="1">
                        <label class="form-check-label" for="edit_active">Active (shown when posting entries)</label>
                    </div>
                    <p class="small text-secondary mt-2 mb-0 d-none" data-system-note>System accounts are always active.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
@endsection

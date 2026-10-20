@extends('layouts.app')

@section('title', 'Chart of accounts')

@php
    // One look per account type, so the five families of the chart are easy to tell apart
    $typeStyles = [
        'asset' => ['icon' => 'safe2', 'tone' => 'asset', 'hint' => 'What the business owns'],
        'liability' => ['icon' => 'credit-card-2-back', 'tone' => 'liability', 'hint' => 'What the business owes'],
        'equity' => ['icon' => 'person-badge', 'tone' => 'equity', 'hint' => "The owner's share"],
        'income' => ['icon' => 'graph-up-arrow', 'tone' => 'income', 'hint' => 'Money earned from sales'],
        'cost_of_sales' => ['icon' => 'box-seam', 'tone' => 'expense', 'hint' => 'Direct cost of what you sell'],
        'expense' => ['icon' => 'receipt', 'tone' => 'expense', 'hint' => 'Day-to-day running costs'],
        'other_income' => ['icon' => 'plus-circle', 'tone' => 'income', 'hint' => 'Income outside normal sales'],
        'other_expense' => ['icon' => 'dash-circle', 'tone' => 'expense', 'hint' => 'Costs outside normal operations'],
    ];
    $sumOf = fn (array $types) => collect($types)->sum(fn ($t) => isset($groups[$t]) ? $groups[$t]->sum(fn ($a) => $balances[$a->id] ?? 0) : 0);
    $summary = [
        ['label' => 'Assets', 'type' => 'asset', 'value' => $sumOf(['asset']), 'sub' => 'as of today'],
        ['label' => 'Liabilities', 'type' => 'liability', 'value' => $sumOf(['liability']), 'sub' => 'as of today'],
        ['label' => 'Equity', 'type' => 'equity', 'value' => $sumOf(['equity']), 'sub' => 'as of today'],
        ['label' => 'Revenue', 'type' => 'income', 'value' => $sumOf(['income', 'other_income']), 'sub' => 'this fiscal year'],
        ['label' => 'Expenses', 'type' => 'expense', 'value' => $sumOf(['cost_of_sales', 'expense', 'other_expense']), 'sub' => 'this fiscal year'],
    ];
@endphp

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

    <div class="coa-summary mb-3">
        @foreach ($summary as $tile)
            <a href="#type-{{ $tile['type'] }}" class="coa-tile tone-{{ $typeStyles[$tile['type']]['tone'] }}">
                <span class="coa-group-icon"><i class="bi bi-{{ $typeStyles[$tile['type']]['icon'] }}"></i></span>
                <span class="min-w-0">
                    <span class="d-block small text-secondary">{{ $tile['label'] }}</span>
                    <span @class(['d-block fw-semibold coa-amount', 'neg' => $tile['value'] < 0])>{{ money($tile['value'], true) }}</span>
                    <span class="d-block coa-tile-sub">{{ $tile['sub'] }}</span>
                </span>
            </a>
        @endforeach
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
        @php
            $style = $typeStyles[$type] ?? ['icon' => 'folder2', 'tone' => 'other', 'hint' => ''];
            $groupTotal = $accounts->sum(fn ($a) => $balances[$a->id] ?? 0);
        @endphp
        <div class="card coa-group tone-{{ $style['tone'] }} mb-3" id="type-{{ $type }}" data-account-group>
            <div class="coa-group-head">
                <span class="coa-group-icon"><i class="bi bi-{{ $style['icon'] }}"></i></span>
                <div class="me-auto">
                    <div class="fw-semibold">{{ $typeLabels[$type] ?? $type }}</div>
                    <div class="small text-secondary">{{ $style['hint'] }} · <span data-group-count>{{ $accounts->count() }}</span> accounts</div>
                </div>
                <div class="text-end">
                    <div class="small text-secondary">Total</div>
                    <div @class(['fw-semibold coa-amount', 'neg' => $groupTotal < 0])>{{ money($groupTotal, true) }}</div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table report-table coa-table table-hover align-middle mb-0">
                    <thead><tr><th style="width: 90px">Code</th><th>Account</th><th class="d-none d-md-table-cell">Section</th><th class="num">Balance</th><th class="text-end" style="width: 110px"><span class="visually-hidden">Actions</span></th></tr></thead>
                    <tbody>
                        @foreach ($accounts as $account)
                            @php $balance = $balances[$account->id] ?? 0; @endphp
                            <tr @class(['is-hidden' => ! $account->is_active]) data-account-row
                                data-search="{{ strtolower($account->code.' '.$account->name) }}"
                                data-used="{{ isset($usedIds[$account->id]) || $account->system_key || $account->created_at?->gt($company->created_at->addMinutes(10)) ? 1 : 0 }}">
                                <td><span class="coa-code">{{ $account->code }}</span></td>
                                <td>
                                    <a href="{{ route('reports.show', ['report' => 'ACC-22', 'account' => $account->id]) }}" class="fw-medium">
                                        @if ($account->is_bank_account)
                                            <i class="bi bi-bank text-secondary me-1" title="Bank account"></i>
                                        @elseif ($account->is_credit_card)
                                            <i class="bi bi-credit-card text-secondary me-1" title="Credit card"></i>
                                        @endif{{ $account->name }}
                                    </a>
                                    @if ($account->system_key)
                                        <span class="coa-tag" title="Used by the system">System</span>
                                    @endif
                                    @if (! $account->is_active)
                                        <span class="coa-tag is-muted">Hidden</span>
                                    @endif
                                </td>
                                <td class="small text-secondary text-nowrap d-none d-md-table-cell">{{ $account->section }}</td>
                                <td @class(['num coa-amount', 'neg' => $balance < 0, 'is-zero' => abs($balance) < 0.005])>{{ money($balance, true) }}</td>
                                <td class="text-end text-nowrap">
                                    <a href="{{ route('reports.show', ['report' => 'ACC-22', 'account' => $account->id]) }}" class="btn btn-sm coa-action" title="Open in the general ledger">
                                        <i class="bi bi-journal-text"></i><span class="visually-hidden">Ledger for {{ $account->name }}</span>
                                    </a>
                                    <button type="button" class="btn btn-sm coa-action" data-bs-toggle="modal" data-bs-target="#editAccountModal" data-edit-account
                                            data-action="{{ route('accounts.update', $account) }}" data-code="{{ $account->code }}" data-name="{{ $account->name }}"
                                            data-active="{{ $account->is_active ? 1 : 0 }}" data-system="{{ $account->system_key ? 1 : 0 }}" title="Edit">
                                        <i class="bi bi-pencil"></i><span class="visually-hidden">Edit {{ $account->name }}</span>
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

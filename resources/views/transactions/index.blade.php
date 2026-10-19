@extends('layouts.app')

@section('title', 'Transactions')

@php
    $sources = ['upload' => 'From a statement', 'manual' => 'Added by hand', 'opening_balance' => 'Starting balance', 'adjustment' => 'Correction', 'reversal' => 'Reversal'];
    $simple = $view === 'simple';
    $viewUrl = fn (string $to) => route('transactions.index', array_filter($filters) + ($to === 'accountant' ? ['view' => 'accountant'] : []));
@endphp

@section('content')
    <div class="page-head">
        <div>
            <h1 class="h3">Transactions</h1>
            <div class="small text-secondary">
                @if ($simple)
                    Every transaction in your books with its category{{ $latest ? ' · latest '.$latest->format('M j, Y') : '' }}.
                @else
                    Every debit and credit line posted to your books{{ $latest ? ' · latest '.$latest->format('M j, Y') : '' }}. To change a posted entry, reverse it and post a new one.
                @endif
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <div class="btn-group btn-group-sm" role="group" aria-label="How to show transactions">
                <a href="{{ $viewUrl('simple') }}" @class(['btn', 'btn-primary' => $simple, 'btn-outline-secondary' => ! $simple]) @if ($simple) aria-current="page" @endif>Simple</a>
                <a href="{{ $viewUrl('accountant') }}" @class(['btn', 'btn-primary' => ! $simple, 'btn-outline-secondary' => $simple]) @if (! $simple) aria-current="page" @endif>Accountant view</a>
            </div>
            <a href="{{ route('journal.create') }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-lg me-1"></i>Journal entry</a>
        </div>
    </div>

    <form method="GET" class="card card-body report-toolbar mb-3 d-flex flex-row flex-wrap align-items-end gap-2">
        @unless ($simple)
            <input type="hidden" name="view" value="accountant">
        @endunless
        <div>
            <label for="q" class="form-label small mb-1">Search</label>
            <input id="q" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Merchant, description..." class="form-control form-control-sm">
        </div>
        <div>
            <label for="start" class="form-label small mb-1">From</label>
            <input id="start" type="date" name="start" value="{{ $filters['start'] ?? '' }}" class="form-control form-control-sm">
        </div>
        <div>
            <label for="end" class="form-label small mb-1">To</label>
            <input id="end" type="date" name="end" value="{{ $filters['end'] ?? '' }}" class="form-control form-control-sm">
        </div>
        <div>
            <label for="account" class="form-label small mb-1">{{ $simple ? 'Account or category' : 'Account' }}</label>
            @php($picked = (string) ($filters['account'] ?? ''))
            <select id="account" name="account" class="form-select form-select-sm" style="max-width: 260px">
                <option value="">All</option>
                <optgroup label="Bank &amp; cards">
                    @foreach ($statementAccounts as $account)
                        <option value="{{ $account->id }}" @selected($picked === (string) $account->id)>{{ $account->name }}</option>
                    @endforeach
                </optgroup>
                <optgroup label="Categories">
                    @foreach ($categories as $category)
                        <option value="type:{{ $category['id'] }}" @selected($picked === 'type:'.$category['id'])>{{ $category['name'] }}</option>
                    @endforeach
                </optgroup>
                <optgroup label="Every account">
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}" @selected($picked === (string) $account->id && ! $account->is_bank_account && ! $account->is_credit_card)>{{ $account->name }} ({{ $account->code }})</option>
                    @endforeach
                </optgroup>
            </select>
        </div>
        <div>
            <label for="source" class="form-label small mb-1">Came from</label>
            <select id="source" name="source" class="form-select form-select-sm">
                <option value="">Anywhere</option>
                @foreach ($sources as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['source'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-sm btn-primary">Filter</button>
        @if (array_filter($filters))
            <a href="{{ route('transactions.index', $simple ? [] : ['view' => 'accountant']) }}" class="btn btn-sm btn-link">Clear</a>
        @endif
    </form>

    @if ($simple)
        <div class="row row-cols-1 row-cols-sm-3 g-2 g-md-3 mb-3">
            <div class="col"><x-kpi label="Transactions" :value="number_format($totals['count'])" /></div>
            <div class="col"><x-kpi label="Money in" :value="money($totals['in'])" sub="Into your bank and cards (transfers left out)" /></div>
            <div class="col"><x-kpi label="Money out" :value="money($totals['out'])" sub="Spent from your bank and cards (transfers left out)" /></div>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table class="table report-table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Date</th><th style="min-width: 200px">Description</th><th>Category</th><th style="min-width: 140px">Bank / card</th>
                            <th class="num">Money in</th><th class="num">Money out</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            @php($entry = $row['entry'])
                            <tr>
                                <td class="text-nowrap small">{{ $entry->entry_date->format('M j, Y') }}</td>
                                <td>
                                    <a href="{{ route('journal.show', $entry) }}" class="text-body">{{ \Illuminate\Support\Str::limit($entry->description, 90) }}</a>
                                    @if ($entry->source !== 'upload' && ($sources[$entry->source] ?? '') !== $entry->description)
                                        <div class="small text-secondary">{{ $sources[$entry->source] ?? $entry->source }}</div>
                                    @endif
                                </td>
                                <td>
                                    @if ($row['categoryLineId'] ?? null)
                                        {{-- Click the category to change it --}}
                                        <button type="button" @class(['category-badge', 'category-edit', 'is-missing' => ! $row['category']])
                                                data-change-category="{{ route('transactions.recategorize', $row['categoryLineId']) }}"
                                                data-description="{{ \Illuminate\Support\Str::limit($entry->description, 80) }}"
                                                data-current="{{ $row['category'] ?? '' }}"
                                                title="Change category">
                                            {{ $row['category'] ?? 'Needs a category' }} <i class="bi bi-pencil"></i>
                                        </button>
                                    @elseif ($row['category'])
                                        <span class="category-badge">{{ $row['category'] }}</span>
                                    @else
                                        <a href="{{ route('review.index') }}" class="category-badge is-missing">Needs a category</a>
                                    @endif
                                </td>
                                <td class="small">{{ $row['through'] ?? '' }}</td>
                                @if ($row['note'])
                                    <td colspan="2" class="num small text-secondary">{{ money($row['amount']) }} {{ $row['note'] }}</td>
                                @else
                                    <td class="num text-success">{{ $row['in'] > 0 ? '+'.money($row['in']) : '' }}</td>
                                    <td class="num">{{ $row['out'] > 0 ? '−'.money($row['out']) : '' }}</td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-5">
                                    <p class="text-secondary mb-2">No transactions match these filters.</p>
                                    @unless (array_filter($filters))
                                        <a href="{{ route('uploads.index') }}" class="btn btn-sm btn-primary"><i class="bi bi-cloud-arrow-up me-1"></i>Dump your data</a>
                                    @endunless
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @include('partials.pagination', ['paginator' => $rows, 'label' => 'transactions', 'perPageOptions' => $perPageOptions])

        {{-- Change category: one dialog, filled in by app.js for the transaction that was clicked --}}
        <div class="modal fade" id="changeCategoryModal" tabindex="-1" aria-labelledby="changeCategoryTitle" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form method="POST" class="modal-content" data-change-category-form>
                    @csrf
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="changeCategoryTitle">Change category</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small mb-1 fw-semibold" data-change-category-description></p>
                        <p class="small text-secondary mb-3">Now: <span data-change-category-current></span></p>
                        <label for="change-category" class="form-label small">New category</label>
                        <select id="change-category" name="category" class="form-select" required>
                            <option value="">Pick a category…</option>
                            <optgroup label="Categories">
                                @foreach ($categories as $category)
                                    <option value="type:{{ $category['id'] }}">{{ $category['name'] }}</option>
                                @endforeach
                            </optgroup>
                            <optgroup label="Every account">
                                @foreach ($accounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->name }} ({{ $account->code }})</option>
                                @endforeach
                            </optgroup>
                        </select>
                        <div class="form-check mt-3">
                            <input class="form-check-input" type="checkbox" name="remember" value="1" id="change-category-remember">
                            <label class="form-check-label small" for="change-category-remember">Always use this category for this merchant</label>
                        </div>
                        <p class="small text-secondary mt-3 mb-0">Your books stay correct: we add a small correcting entry on the same date, so the report for that month shows the new category.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save category</button>
                    </div>
                </form>
            </div>
        </div>
    @else
        <div class="row row-cols-1 row-cols-sm-3 g-2 g-md-3 mb-3">
            <div class="col"><x-kpi label="Lines" :value="number_format($totals->n ?? 0)" /></div>
            <div class="col"><x-kpi label="Total debits" :value="money($totals->debit ?? 0)" /></div>
            <div class="col"><x-kpi label="Total credits" :value="money($totals->credit ?? 0)" /></div>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table class="table report-table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Date</th><th>Entry</th><th>Description</th><th>Account</th>
                            <th class="num">Debit</th><th class="num">Credit</th><th>Source</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $line)
                            <tr>
                                <td class="text-nowrap">{{ $line->entry_date->format('M j, Y') }}</td>
                                <td><a href="{{ route('journal.show', $line->journal_entry_id) }}">#{{ $line->journalEntry->entry_number }}</a></td>
                                <td>{{ $line->description ?: $line->journalEntry->description }}</td>
                                <td class="text-nowrap">{{ $line->account->code }} · {{ $line->account->name }}</td>
                                <td class="num">{{ $line->debit > 0 ? money($line->debit) : '' }}</td>
                                <td class="num">{{ $line->credit > 0 ? money($line->credit) : '' }}</td>
                                <td><span class="badge text-bg-light border">{{ $sources[$line->journalEntry->source] ?? $line->journalEntry->source }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-secondary py-4">No transactions match these filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @include('partials.pagination', ['paginator' => $rows, 'label' => 'lines', 'perPageOptions' => $perPageOptions])
    @endif
@endsection

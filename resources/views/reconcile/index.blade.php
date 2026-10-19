@extends('layouts.app')

@section('title', 'Reconcile')

@section('content')
    <div class="page-head">
        <div>
            <h1 class="h3">Reconcile</h1>
            <div class="small text-secondary">Prove your books match the bank: tick the transactions that appear on the statement until the difference is $0.00.</div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-xl-7">
            <form method="POST" action="{{ route('reconcile.store') }}" class="card h-100" data-reconcile-start novalidate>
                @csrf
                <div class="card-body p-4">
                    <h2 class="card-title-sm mb-3">Start a reconciliation</h2>
                    <div class="row g-2">
                        <div class="col-md-12">
                            <div class="form-floating">
                                <select id="account_id" name="account_id" class="form-select @error('account_id') is-invalid @enderror" required>
                                    @foreach ($accounts as $account)
                                        <option value="{{ $account->id }}" @selected((int) old('account_id') === $account->id)>{{ $account->code }} · {{ $account->name }}</option>
                                    @endforeach
                                </select>
                                <label for="account_id">Account</label>
                                @error('account_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-floating">
                                <input id="statement_date" type="date" name="statement_date" value="{{ old('statement_date', now()->subMonthNoOverflow()->endOfMonth()->toDateString()) }}" placeholder="Date"
                                       class="form-control @error('statement_date') is-invalid @enderror" required>
                                <label for="statement_date">Statement ending date</label>
                                @error('statement_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-floating">
                                <input id="statement_balance" name="statement_balance" value="{{ old('statement_balance') }}" inputmode="decimal" placeholder="0.00"
                                       class="form-control @error('statement_balance') is-invalid @enderror" required>
                                <label for="statement_balance">Statement ending balance</label>
                                @error('statement_balance') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>
                    <p class="form-text mb-0 mt-2">For a credit card, enter the balance you owe as a positive number.</p>

                    @if ($suggestions->isNotEmpty())
                        <div class="mt-3">
                            <div class="small text-secondary mb-1">From your uploaded statements:</div>
                            <div class="d-flex flex-wrap gap-2">
                                @foreach ($suggestions as $s)
                                    <button type="button" class="btn btn-sm btn-light border" data-fill-reconcile
                                            data-account="{{ $s->account_id }}" data-date="{{ $s->statement_end_date->toDateString() }}" data-balance="{{ $s->closing_balance }}">
                                        {{ $s->statement_end_date->format('M j, Y') }} · {{ money($s->closing_balance) }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
                <div class="card-footer bg-white d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary">Start reconciling</button>
                </div>
            </form>
        </div>

        <div class="col-xl-5">
            <div class="card h-100">
                <div class="card-body p-4">
                    <h2 class="card-title-sm mb-3">In progress</h2>
                    @forelse ($open as $rec)
                        <a href="{{ route('reconcile.show', $rec) }}" class="report-tile mb-2">
                            <span class="kpi-icon"><i class="bi bi-arrow-left-right"></i></span>
                            <span class="min-w-0">
                                <span class="d-block fw-semibold text-truncate">{{ $rec->account->name }}</span>
                                <span class="d-block small text-secondary">Statement {{ $rec->statement_date->format('M j, Y') }} · {{ money($rec->statement_balance) }}</span>
                            </span>
                            <i class="bi bi-chevron-right ms-auto text-secondary"></i>
                        </a>
                    @empty
                        <p class="small text-secondary mb-0">No reconciliation in progress.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header-flex"><h2 class="card-title-sm">Completed</h2></div>
        <div class="table-responsive">
            <table class="table report-table mb-0">
                <thead><tr><th>Account</th><th>Statement date</th><th class="num">Statement balance</th><th>Completed</th><th>By</th></tr></thead>
                <tbody>
                    @forelse ($history as $rec)
                        <tr>
                            <td><a href="{{ route('reconcile.show', $rec) }}">{{ $rec->account->code }} · {{ $rec->account->name }}</a></td>
                            <td>{{ $rec->statement_date->format('M j, Y') }}</td>
                            <td class="num">{{ money($rec->statement_balance) }}</td>
                            <td class="small">{{ $rec->completed_at?->format('M j, Y') }}</td>
                            <td class="small">{{ $rec->completedBy?->name }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-secondary py-4">No completed reconciliations yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @include('partials.pagination', ['paginator' => $history, 'label' => 'reconciliations'])
@endsection

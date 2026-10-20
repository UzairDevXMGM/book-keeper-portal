@extends('layouts.app')

@section('title', 'Reconcile '.$account->name)

@php
    $open = $reconciliation->isOpen();
    // Effect of a line on the balance as the statement shows it (bank: money held; card: amount owed)
    $effect = fn ($line) => round($creditNormal ? $line->credit - $line->debit : $line->debit - $line->credit, 2);
    [$increaseLabel, $decreaseLabel] = $creditNormal ? ['Charges', 'Payments'] : ['Deposits', 'Withdrawals'];
@endphp

@section('content')
    <a href="{{ route('reconcile.index') }}" class="small"><i class="bi bi-arrow-left me-1"></i>Reconcile</a>
    <div class="page-head mt-1">
        <div>
            <h1 class="h3">{{ $account->code }} · {{ $account->name }}</h1>
            <div class="small text-secondary">
                Statement ending {{ $reconciliation->statement_date->format('M j, Y') }}
                @unless ($open) · completed {{ $reconciliation->completed_at?->format('M j, Y') }} @endunless
            </div>
        </div>
        @if ($open)
            <form method="POST" action="{{ route('reconcile.destroy', $reconciliation) }}" data-confirm="Your ticks are cleared. Your books are not changed." data-confirm-title="Discard this reconciliation?" data-confirm-button="Discard" data-confirm-tone="danger">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-sm btn-outline-danger">Discard</button>
            </form>
        @endif
    </div>

    <form method="POST" action="{{ route('reconcile.update', $reconciliation) }}" data-reconcile
          data-opening="{{ $reconciliation->opening_balance }}" data-statement="{{ $reconciliation->statement_balance }}">
        @csrf
        @method('PATCH')

        <div class="card reconcile-bar mb-3">
            <div class="card-body d-flex flex-wrap align-items-center gap-4">
                <div><div class="kpi-label">Statement balance</div><div class="metric-value">{{ money($reconciliation->statement_balance) }}</div></div>
                <div><div class="kpi-label">Last reconciled</div><div class="metric-value">{{ money($reconciliation->opening_balance) }}</div></div>
                <div><div class="kpi-label">Cleared balance</div><div class="metric-value" data-cleared>—</div></div>
                <div><div class="kpi-label">Difference</div><div class="metric-value" data-difference>—</div></div>
                @if ($open)
                    <div class="ms-auto d-flex flex-wrap align-items-center gap-2">
                        <div class="form-check me-2">
                            <input class="form-check-input" type="checkbox" name="lock" value="1" id="lock" checked>
                            <label class="form-check-label small" for="lock">Lock books through this date</label>
                        </div>
                        <button type="submit" class="btn btn-outline-secondary" name="finish" value="0">Save progress</button>
                        <button type="submit" class="btn btn-primary" name="finish" value="1" data-finish disabled>Finish</button>
                    </div>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table class="table report-table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width: 32px">
                                @if ($open)<input type="checkbox" class="form-check-input" data-check-all aria-label="Tick every line">@endif
                            </th>
                            <th>Date</th><th>Entry</th><th>Description</th>
                            <th class="num">{{ $increaseLabel }}</th><th class="num">{{ $decreaseLabel }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($lines as $line)
                            @php $value = $effect($line); @endphp
                            <tr>
                                <td>
                                    <input type="checkbox" class="form-check-input" name="cleared[]" value="{{ $line->id }}" data-row-check
                                           data-effect="{{ $value }}" @checked($line->reconciliation_id === $reconciliation->id) @disabled(! $open)
                                           aria-label="Cleared: {{ $line->description ?: $line->journalEntry->description }}">
                                </td>
                                <td class="text-nowrap">{{ $line->entry_date->format('M j, Y') }}</td>
                                <td><a href="{{ route('journal.show', $line->journal_entry_id) }}">#{{ $line->journalEntry->entry_number }}</a></td>
                                <td>{{ $line->description ?: $line->journalEntry->description }}</td>
                                <td class="num">{{ $value > 0 ? money($value) : '' }}</td>
                                <td class="num">{{ $value < 0 ? money(abs($value)) : '' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-secondary py-4">No unreconciled transactions up to this date.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </form>
@endsection

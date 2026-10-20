@extends('layouts.app')

@section('title', "Entry #{$entry->entry_number}")

@php
    $sources = ['upload' => 'Upload', 'manual' => 'Manual', 'opening_balance' => 'Opening balance', 'adjustment' => 'Adjustment', 'reversal' => 'Reversal'];
@endphp

@section('content')
    <a href="{{ route('transactions.index') }}" class="small"><i class="bi bi-arrow-left me-1"></i>Transactions</a>
    <div class="page-head mt-1">
        <div>
            <h1 class="h3">Entry #{{ $entry->entry_number }}</h1>
            <div class="small text-secondary">
                {{ $entry->entry_date->format('M j, Y') }} · {{ $sources[$entry->source] ?? $entry->source }}
                @if ($entry->reference) · Ref {{ $entry->reference }} @endif
                @if ($entry->creator) · posted by {{ $entry->creator->name }} @endif
            </div>
        </div>
        @if ($entry->source !== 'reversal' && ! $reversedBy)
            <form method="POST" action="{{ route('journal.reverse', $entry) }}" data-confirm="A reversing entry dated today cancels entry #{{ $entry->entry_number }}. The original stays in your books for the record." data-confirm-title="Reverse this entry?" data-confirm-button="Reverse entry" data-confirm-tone="danger">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-arrow-counterclockwise me-1"></i>Reverse entry</button>
            </form>
        @endif
    </div>

    @if ($reversedBy)
        <div class="alert alert-warning small">This entry was cancelled by <a href="{{ route('journal.show', $reversedBy) }}">entry #{{ $reversedBy->entry_number }}</a>.</div>
    @endif
    @if ($entry->reverses)
        <div class="alert alert-info small">This entry reverses <a href="{{ route('journal.show', $entry->reverses) }}">entry #{{ $entry->reverses->entry_number }}</a>.</div>
    @endif

    <div class="card">
        <div class="card-body pb-0">
            <p class="mb-3">{{ $entry->description }}</p>
        </div>
        <div class="table-responsive">
            <table class="table report-table is-drcr mb-0">
                <thead><tr><th>Account</th><th>Description</th><th class="num col-debit">Debit</th><th class="num col-credit">Credit</th></tr></thead>
                <tbody>
                    @foreach ($entry->lines as $line)
                        <tr>
                            <td><a href="{{ route('reports.show', ['report' => 'ACC-22', 'account' => $line->account_id]) }}">{{ $line->account->code }} · {{ $line->account->name }}</a></td>
                            <td>{{ $line->description }}</td>
                            <td class="num col-debit">{{ $line->debit > 0 ? money($line->debit) : '' }}</td>
                            <td class="num col-credit">{{ $line->credit > 0 ? money($line->credit) : '' }}</td>
                        </tr>
                    @endforeach
                    <tr class="row-grand">
                        <td colspan="2">Total</td>
                        <td class="num col-debit">{{ money($entry->lines->sum('debit')) }}</td>
                        <td class="num col-credit">{{ money($entry->lines->sum('credit')) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
@endsection

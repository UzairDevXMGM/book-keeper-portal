@extends('layouts.app')

@section('title', 'Invoice '.($invoice->number ?? ''))

@php
    $address = $stripeInvoice->customer_address;
    $paidAt = $stripeInvoice->status_transitions->paid_at ?? null;
@endphp

@section('content')
    <a href="{{ route('profile.show') }}#billing" class="small no-print"><i class="bi bi-arrow-left me-1"></i>Plan &amp; billing</a>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-1 mb-3 no-print">
        <h1 class="h3 mb-0">Invoice {{ $invoice->number }}</h1>
        <a href="{{ route('billing.invoice', [$invoice->id, 'print' => 1]) }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-printer me-1"></i>Print / Save as PDF
        </a>
    </div>
    @if ($printing)
        <div data-auto-print hidden></div>
    @endif

    <div class="card invoice-sheet">
        <div class="card-body p-4 p-md-5">
            <div class="d-flex flex-wrap justify-content-between gap-4 mb-4">
                <div>
                    <img src="{{ asset('images/logo.png') }}" alt="BookKeepingLLC" class="invoice-logo mb-2">
                    <div class="small text-secondary">BookKeepingLLC<br>{{ config('mail.from.address') }}</div>
                </div>
                <div class="text-md-end">
                    <div class="invoice-title">Invoice</div>
                    <div class="small"><span class="text-secondary">Number</span> <b>{{ $invoice->number }}</b></div>
                    <div class="small"><span class="text-secondary">Date</span> {{ $invoice->date()->format('M j, Y') }}</div>
                    @if ($paidAt)
                        <div class="small"><span class="text-secondary">Paid on</span> {{ \Illuminate\Support\Carbon::createFromTimestamp($paidAt)->format('M j, Y') }}</div>
                    @endif
                    <span @class(['badge rounded-pill mt-2', 'text-bg-success' => $invoice->isPaid(), 'text-bg-warning' => ! $invoice->isPaid()])>
                        {{ $invoice->isPaid() ? 'Paid' : ucfirst((string) $stripeInvoice->status) }}
                    </span>
                </div>
            </div>

            <div class="mb-4">
                <div class="kpi-label mb-1">Billed to</div>
                <div class="fw-semibold">{{ $stripeInvoice->customer_name ?: $company->name }}</div>
                <div class="small text-secondary">
                    @if ($address)
                        {{ $address->line1 }}@if ($address->line2), {{ $address->line2 }}@endif<br>
                        {{ collect([$address->city, $address->state, $address->postal_code])->filter()->implode(', ') }} {{ $address->country }}<br>
                    @endif
                    {{ $stripeInvoice->customer_email ?: $company->owner?->email }}
                </div>
            </div>

            <div class="table-responsive">
                <table class="table report-table mb-0">
                    <thead><tr><th>Description</th><th>Period</th><th class="num">Amount</th></tr></thead>
                    <tbody>
                        @foreach ($lines as $line)
                            <tr>
                                <td>{{ $line->description }}</td>
                                <td class="small text-secondary text-nowrap">
                                    @if ($line->hasPeriod())
                                        {{ $line->startDateAsCarbon()->format('M j, Y') }}@unless ($line->periodStartAndEndAreEqual()) – {{ $line->endDateAsCarbon()->format('M j, Y') }}@endunless
                                    @endif
                                </td>
                                <td class="num">{{ $line->total() }}</td>
                            </tr>
                        @endforeach
                        <tr class="row-subtotal"><td colspan="2" class="text-end">Subtotal</td><td class="num">{{ $invoice->subtotal() }}</td></tr>
                        @if ($invoice->hasDiscount())
                            <tr><td colspan="2" class="text-end">Discount</td><td class="num">−{{ $invoice->discount() }}</td></tr>
                        @endif
                        @if ($invoice->hasTax())
                            <tr><td colspan="2" class="text-end">Tax</td><td class="num">{{ $invoice->tax() }}</td></tr>
                        @endif
                        @if ($invoice->hasStartingBalance())
                            <tr><td colspan="2" class="text-end">Credit applied</td><td class="num">{{ $invoice->startingBalance() }}</td></tr>
                        @endif
                        <tr class="row-total"><td colspan="2" class="text-end">Total</td><td class="num">{{ $invoice->total() }}</td></tr>
                        <tr class="row-grand"><td colspan="2" class="text-end">{{ $invoice->isPaid() ? 'Amount paid' : 'Amount due' }}</td><td class="num">{{ $invoice->isPaid() ? $invoice->amountPaid() : $invoice->amountDue() }}</td></tr>
                    </tbody>
                </table>
            </div>

            <p class="small text-secondary mt-4 mb-0">
                Thank you for your business. Questions about this invoice? Write to {{ config('mail.from.address') }}.
            </p>
        </div>
    </div>
@endsection

@extends('layouts.app')

@section('title', $upload->original_name)

@php
    $rowBadges = [
        'posted' => ['In your books', 'text-bg-primary'],
        'pending' => ['Ready to add', 'text-bg-info'],
        'needs_review' => ['Check category', 'text-bg-warning'],
        'duplicate' => ['Already uploaded', 'text-bg-light border'],
        'other_year' => ['Different year', 'text-bg-light border'],
        'error' => ["Couldn't read", 'text-bg-danger'],
        'ignored' => ['Skipped', 'text-bg-secondary'],
    ];
    $ready = $counts['pending'] ?? 0;
    $review = $counts['needs_review'] ?? 0;
    $otherYear = $counts['other_year'] ?? 0;
    $isCard = $upload->account?->is_credit_card;
    // Statement check: bank balance grows with money in; a card balance (amount owed) grows with money out
    $check = null;
    if ($upload->opening_balance !== null && $upload->closing_balance !== null) {
        $expected = $isCard
            ? (float) $upload->opening_balance + $moneyOut - $moneyIn
            : (float) $upload->opening_balance + $moneyIn - $moneyOut;
        $check = round((float) $upload->closing_balance - $expected, 2);
    }
@endphp

@section('content')
    <a href="{{ route('uploads.index') }}" class="small"><i class="bi bi-arrow-left me-1"></i>Dump your data</a>
    <div class="page-head mt-1">
        <div>
            <h1 class="h3 d-flex align-items-center gap-2"><i class="bi bi-filetype-{{ $upload->file_type }} text-secondary"></i>{{ $upload->original_name }}</h1>
            <div class="small text-secondary">
                {{ $upload->account?->name }}
                @if ($upload->year) · for {{ $upload->year }} @endif
                @if ($upload->statement_start_date) · {{ $upload->statement_start_date->format('M j, Y') }} – {{ $upload->statement_end_date?->format('M j, Y') }} @endif
                · uploaded {{ $upload->created_at->format('M j, Y g:i A') }}{{ $upload->user ? ' by '.$upload->user->name : '' }}
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('uploads.download', $upload) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-download me-1"></i>Original file</a>
            @if ($review)
                <a href="{{ route('review.index', ['upload' => $upload->id]) }}" class="btn btn-sm btn-warning"><i class="bi bi-tags me-1"></i>Check {{ $review }} categor{{ $review === 1 ? 'y' : 'ies' }}</a>
            @endif
            @if ($ready)
                <form method="POST" action="{{ route('uploads.post', $upload) }}">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-journal-check me-1"></i>Add {{ $ready }} to my books</button>
                </form>
            @endif
            @if (! ($counts['posted'] ?? 0))
                <form method="POST" action="{{ route('uploads.destroy', $upload) }}" data-confirm="The file and the lines read from it are deleted. Nothing in your books changes." data-confirm-title="Delete this upload?" data-confirm-button="Delete upload" data-confirm-tone="danger">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Delete</button>
                </form>
            @endif
        </div>
    </div>

    @if ($upload->status === 'failed')
        <div class="card border-danger-subtle mb-4">
            <div class="card-body p-4 d-flex gap-3">
                <i class="bi bi-exclamation-octagon fs-3 text-danger"></i>
                <div>
                    <h2 class="h5 mb-1">This file could not be read</h2>
                    <p class="mb-2">{{ $upload->error_message }}</p>
                    <a href="{{ route('uploads.index') }}" class="btn btn-sm btn-outline-secondary">Upload another file</a>
                </div>
            </div>
        </div>
    @else
        <div class="row row-cols-2 row-cols-md-3 row-cols-xl-6 g-2 g-md-3 mb-3">
            <div class="col"><x-kpi label="Transactions read" :value="number_format($upload->rows_total)" icon="list-ol" /></div>
            <div class="col"><x-kpi label="In your books" :value="number_format($counts['posted'] ?? 0)" icon="journal-check" /></div>
            <div class="col"><x-kpi label="Need a category" :value="number_format($review)" icon="tags" :tone="$review ? 'bad' : null" /></div>
            <div class="col"><x-kpi :label="$otherYear ? 'Different year' : 'Already uploaded'" :value="number_format($otherYear ?: ($counts['duplicate'] ?? 0))" icon="files" /></div>
            <div class="col"><x-kpi label="Money in" :value="money($moneyIn)" icon="arrow-down-left" /></div>
            <div class="col"><x-kpi label="Money out" :value="money($moneyOut)" icon="arrow-up-right" /></div>
        </div>

        @if ($check !== null)
            <div @class(['alert small d-flex gap-2 align-items-center', 'alert-success' => abs($check) < 0.005, 'alert-warning' => abs($check) >= 0.005])>
                <i @class(['bi', 'bi-check-circle-fill' => abs($check) < 0.005, 'bi-exclamation-triangle-fill' => abs($check) >= 0.005])></i>
                @if (abs($check) < 0.005)
                    Statement balances agree: starting balance {{ money($upload->opening_balance) }} plus these transactions equals the ending balance {{ money($upload->closing_balance) }}. Nothing was missed.
                @else
                    The starting balance {{ money($upload->opening_balance) }} plus these transactions does not reach the ending balance {{ money($upload->closing_balance) }}: off by {{ money($check) }}. A transaction may be missing or read the wrong way round.
                @endif
            </div>
        @endif
        @if ($otherYear)
            <div class="alert alert-secondary small d-flex gap-2 align-items-center">
                <i class="bi bi-calendar-x"></i>
                @if ($otherYear === 1)
                    1 transaction in this file is from another year than {{ $upload->year }}, so it was left out of your books.
                    To add it, upload the same file again and pick its year.
                @else
                    {{ $otherYear }} transactions in this file are from another year than {{ $upload->year }}, so they were left out of your books.
                    To add them, upload the same file again and pick their year.
                @endif
            </div>
        @endif

        <ul class="nav nav-pills nav-pills-sm mb-3 flex-wrap gap-1">
            @foreach ($filters as $value => $label)
                @php $n = $value === 'all' ? $counts->sum() : ($counts[$value] ?? 0); @endphp
                @if ($value === 'all' || $n)
                    <li class="nav-item">
                        <a href="{{ route('uploads.show', ['upload' => $upload, 'status' => $value]) }}" @class(['nav-link', 'active' => $filter === $value])>
                            {{ $label }} <span class="badge rounded-pill text-bg-light border ms-1">{{ $n }}</span>
                        </a>
                    </li>
                @endif
            @endforeach
        </ul>

        <div class="card">
            <div class="table-responsive">
                <table class="table report-table table-hover mb-0">
                    <thead>
                        <tr><th>Date</th><th>Description</th><th>Category</th><th class="num">Money in</th><th class="num">Money out</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            @php
                                [$label, $badge] = $rowBadges[$row->status] ?? [$row->status, 'text-bg-light'];
                                $category = in_array($row->status, ['duplicate', 'error', 'other_year', 'ignored'], true) ? false : $row->categoryLabel();
                                $how = \App\Models\ImportRow::METHOD_LABELS[$row->match_method] ?? null;
                            @endphp
                            <tr>
                                <td class="text-nowrap">{{ $row->txn_date?->format('M j, Y') ?? '—' }}</td>
                                <td>
                                    @php $merchant = $row->description ? \App\Support\MerchantName::for($row) : null; @endphp
                                    @if ($merchant)
                                        <span class="fw-semibold">{{ $merchant }}</span>
                                        @if ($row->business_type) <span class="business-type">{{ $row->business_type }}</span> @endif
                                        @if (\App\Services\Import\Categorizer::normalize($merchant) !== \App\Services\Import\Categorizer::normalize((string) $row->description))
                                            <div class="small text-secondary">{{ $row->description }}</div>
                                        @endif
                                    @else
                                        {{ $row->description ?? '—' }}
                                    @endif
                                    @if ($row->error_message)
                                        <div class="small text-secondary">{{ $row->error_message }}</div>
                                    @endif
                                </td>
                                <td>
                                    @if ($category === false)
                                        <span class="text-secondary small">—</span>
                                    @elseif ($category)
                                        <span class="category-badge"><i class="bi bi-tag"></i>{{ $category }}</span>
                                        @if ($how && $row->match_method !== 'suspense')
                                            <div class="small text-secondary mt-1">
                                                {{ $how }}{{ $row->rule && in_array($row->match_method, ['vendor', 'bank_rule', 'user'], true) ? ' · '.preg_replace('/\s*\(alt\)$/i', '', $row->rule->name ?: $row->rule->keyword) : '' }}
                                                · {{ $row->confidence }}% sure
                                            </div>
                                        @endif
                                    @else
                                        <span class="category-badge is-missing"><i class="bi bi-question-circle"></i>Needs a category</span>
                                    @endif
                                </td>
                                <td class="num text-success">{{ $row->direction === 'in' ? money($row->amount) : '' }}</td>
                                <td class="num">{{ $row->direction === 'out' ? money($row->amount) : '' }}</td>
                                <td class="text-nowrap">
                                    @if ($row->journal_entry_id)
                                        <a href="{{ route('journal.show', $row->journal_entry_id) }}" class="badge {{ $badge }} text-decoration-none">{{ $label }}</a>
                                    @else
                                        <span class="badge {{ $badge }}">{{ $label }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-secondary py-4">No transactions with this status.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @include('partials.pagination', ['paginator' => $rows, 'label' => 'transactions'])
    @endif
@endsection

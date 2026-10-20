@extends('layouts.app')

@section('title', 'Check categories')

@php
    $typeLabels = \App\Http\Controllers\AccountController::TYPE_LABELS;
    $grouped = $accounts->groupBy('type');
    // Category picker: plain categories first ("Fuel"), then every account for whoever wants the full chart
    $suspenseIds = $accounts->whereIn('system_key', ['suspense_expense', 'suspense_income'])->pluck('id')->all();
    $categoryOptions = function (?int $accountId, ?int $typeId) use ($grouped, $typeLabels, $choices, $suspenseIds) {
        // No real guess yet (Uncategorized): start on "Pick a category" instead of the Uncategorized account
        $unsure = $accountId === null || in_array($accountId, $suspenseIds, true);
        $html = $unsure ? '<option value="" selected>Pick a category…</option>' : '';
        $accountId = $unsure ? null : $accountId;
        $html .= '<optgroup label="Categories">';
        $picked = false;
        foreach ($choices as $choice) {
            $selected = ! $picked && $typeId === $choice['id'];
            $picked = $picked || $selected;
            $html .= '<option value="type:'.$choice['id'].'"'.($selected ? ' selected' : '').'>'.e($choice['name']).'</option>';
        }
        $html .= '</optgroup>';
        foreach ($grouped as $type => $list) {
            $html .= '<optgroup label="'.e('Every account: '.($typeLabels[$type] ?? $type)).'">';
            foreach ($list as $account) {
                $selected = ! $picked && $accountId === $account->id;
                $picked = $picked || $selected;
                $html .= '<option value="'.$account->id.'"'.($selected ? ' selected' : '').'>'.e($account->name.' ('.$account->code.')').'</option>';
            }
            $html .= '</optgroup>';
        }

        return $html;
    };
    $methodLabels = \App\Models\ImportRow::METHOD_LABELS;
@endphp

@section('content')
    <div class="page-head">
        <div>
            <h1 class="h3">Check categories</h1>
            <div class="small text-secondary">We were not sure about these transactions. Our best guess is already picked: change it if it is wrong, then press Approve. Tick “Always” and we remember that merchant.</div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('rules.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-magic me-1"></i>My rules</a>
            <a href="{{ route('uploads.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-cloud-arrow-up me-1"></i>Dump your data</a>
        </div>
    </div>

    @if ($uploadId)
        <div class="mb-3">
            <span class="badge rounded-pill text-bg-light border p-2">
                Showing one upload only
                <a href="{{ route('review.index') }}" class="ms-1" aria-label="Show all uploads"><i class="bi bi-x-lg"></i></a>
            </span>
        </div>
    @endif

    {{-- Uploaded lines waiting for a decision --}}
    <form id="bulk-form" method="POST" action="{{ route('review.bulk') }}" data-bulk-form>
        @csrf
    </form>

    <div class="card mb-4">
        <div class="card-header-flex align-items-center flex-wrap">
            <div>
                <h2 class="card-title-sm">Waiting for you <span class="badge rounded-pill text-bg-light border ms-1">{{ $rows->total() }}</span></h2>
                @if ($rows->total() > $similar->count())
                    <div class="small text-secondary">From {{ $similar->count() }} {{ \Illuminate\Support\Str::plural('merchant', $similar->count()) }}: answer one with “Always” and the others like it are done too.</div>
                @endif
            </div>
            @if ($rows->total())
                <div class="d-flex flex-wrap gap-2">
                    <a href="{{ request()->fullUrlWithQuery(['sort' => $byMerchant ? null : 'merchant', 'page' => null]) }}" class="btn btn-sm btn-light border">
                        <i class="bi bi-sort-alpha-down me-1"></i>{{ $byMerchant ? 'Sort by date' : 'Group by merchant' }}
                    </a>
                    <button type="submit" form="bulk-form" name="action" value="approve" class="btn btn-sm btn-primary" data-bulk-submit>
                        <i class="bi bi-check2-all me-1"></i>Approve ticked
                    </button>
                    <button type="submit" form="bulk-form" name="action" value="ignore" class="btn btn-sm btn-outline-secondary" data-bulk-submit>
                        Skip ticked
                    </button>
                </div>
            @endif
        </div>

        <div class="table-responsive">
            <table class="table report-table align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width: 32px"><input type="checkbox" class="form-check-input" data-check-all aria-label="Tick all on this page"></th>
                        <th>Date</th><th>Description</th><th class="num">Amount</th><th style="min-width: 260px">Category</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php
                            $keyword = \App\Services\Import\Categorizer::keywordFor((string) $row->description);
                            $guess = $row->categoryLabel();
                            $alike = ($similar[$keyword.'|'.$row->direction] ?? 1) - 1;   // other waiting lines from the same merchant
                        @endphp
                        <tr>
                            <td><input type="checkbox" class="form-check-input" name="rows[]" value="{{ $row->id }}" form="bulk-form" data-row-check aria-label="Tick {{ $row->description }}"></td>
                            <td class="text-nowrap small">{{ $row->txn_date?->format('M j, Y') }}</td>
                            <td>
                                <div>
                                    {{ $row->description }}
                                    @if ($alike > 0)
                                        <span class="badge rounded-pill text-bg-warning ms-1" title="Tick “Always” and these are done with the same category">+{{ $alike }} like this</span>
                                    @endif
                                </div>
                                <div class="small text-secondary">
                                    {{ $row->upload?->account?->name }} ·
                                    @if ($guess)
                                        Our guess: <strong>{{ $guess }}</strong> ({{ $row->business_type ? $row->business_type." · " : "" }}{{ $methodLabels[$row->match_method] ?? 'rule' }}, {{ $row->confidence }}% sure)
                                    @else
                                        We could not tell. Please pick a category.
                                    @endif
                                    @if ($row->error_message) <span class="text-danger">· {{ $row->error_message }}</span> @endif
                                </div>
                            </td>
                            <td @class(['num text-nowrap', 'text-success' => $row->direction === 'in'])>
                                {{ $row->direction === 'in' ? '+' : '−' }}{{ money($row->amount) }}
                            </td>
                            <td>
                                <select name="account_id" form="row-{{ $row->id }}" class="form-select form-select-sm" data-row-account="{{ $row->id }}" aria-label="Category for {{ $row->description }}">
                                    {!! $categoryOptions($row->account_id, $row->category_type_id) !!}
                                </select>
                                <div class="form-check mt-1">
                                    <input type="hidden" name="remember" value="0" form="row-{{ $row->id }}">
                                    {{-- Ticked already when more lines from this merchant are waiting --}}
                                    <input class="form-check-input" type="checkbox" name="remember" value="1" id="remember-{{ $row->id }}" form="row-{{ $row->id }}" @checked($alike > 0)>
                                    <label class="form-check-label small" for="remember-{{ $row->id }}">Always for “{{ $keyword }}”{{ $alike > 0 ? " (and the {$alike} like it)" : '' }}</label>
                                    <input type="hidden" name="keyword" value="{{ $keyword }}" form="row-{{ $row->id }}">
                                </div>
                            </td>
                            <td class="text-end">
                                <form id="row-{{ $row->id }}" method="POST" action="{{ route('review.approve', $row) }}">@csrf</form>
                                <button type="submit" form="row-{{ $row->id }}" class="btn btn-sm btn-outline-primary text-nowrap"><i class="bi bi-check-lg me-1"></i>Approve</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-5">
                                <i class="bi bi-check2-circle fs-2 text-success"></i>
                                <p class="text-secondary mb-0 mt-1">All done. Every uploaded transaction has a category and is in your books.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @include('partials.pagination', ['paginator' => $rows, 'label' => 'transactions'])

    {{-- Already in the books without a category --}}
    <div class="card mt-4">
        <div class="card-header-flex">
            <div>
                <h2 class="card-title-sm">In your books without a category <span class="badge rounded-pill text-bg-light border ms-1">{{ $suspense->total() }}</span></h2>
                <div class="small text-secondary">These were added as “Uncategorized”. Pick a category and we move the amount there (with a correcting entry dated today).</div>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table report-table align-middle mb-0">
                <thead><tr><th>Date</th><th>Description</th><th class="num">Amount</th><th style="min-width: 260px">Move to</th><th></th></tr></thead>
                <tbody>
                    @forelse ($suspense as $line)
                        <tr>
                            <td class="text-nowrap small">{{ $line->entry_date->format('M j, Y') }}</td>
                            <td><a href="{{ route('journal.show', $line->journal_entry_id) }}">{{ $line->description ?: $line->journalEntry->description }}</a></td>
                            <td class="num">{{ money($line->debit > 0 ? $line->debit : $line->credit) }}</td>
                            <td>
                                <form id="reclass-{{ $line->id }}" method="POST" action="{{ route('review.reclassify', $line) }}">@csrf</form>
                                <select name="account_id" form="reclass-{{ $line->id }}" class="form-select form-select-sm" aria-label="Category for {{ $line->description }}" required>
                                    {!! $categoryOptions(null, null) !!}
                                </select>
                            </td>
                            <td class="text-end"><button type="submit" form="reclass-{{ $line->id }}" class="btn btn-sm btn-outline-primary">Move</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-secondary py-4">Nothing here.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @include('partials.pagination', ['paginator' => $suspense, 'label' => 'transactions'])
@endsection

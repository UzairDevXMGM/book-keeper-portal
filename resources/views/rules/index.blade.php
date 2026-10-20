@extends('layouts.app')

@section('title', 'My rules')

@section('content')
    <div class="page-head">
        <div>
            <h1 class="h3">My rules</h1>
            <div class="small text-secondary">Merchants you told us to always categorize the same way (the “Always” tick). They are used on every new upload. Change a category or delete a rule here.</div>
        </div>
        <a href="{{ route('review.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-check2-square me-1"></i>Check categories</a>
    </div>

    <form method="GET" class="card card-body report-toolbar mb-3 d-flex flex-row flex-wrap align-items-end gap-2">
        <div>
            <label for="q" class="form-label small mb-1">Search</label>
            <input id="q" type="search" name="q" value="{{ $search }}" placeholder="Merchant" class="form-control form-control-sm">
        </div>
        <button type="submit" class="btn btn-sm btn-primary">Search</button>
        @if ($search !== '')
            <a href="{{ route('rules.index') }}" class="btn btn-sm btn-link">Clear</a>
        @endif
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table report-table align-middle mb-0">
                <thead><tr><th>When the description has</th><th>Money</th><th style="min-width: 260px">Category</th><th></th></tr></thead>
                <tbody>
                    @forelse ($rules as $rule)
                        @php $current = $rule->categoryType?->name ?? $rule->account?->name; @endphp
                        <tr>
                            <td class="fw-semibold">{{ $rule->keyword }}</td>
                            <td class="small text-secondary">{{ ['in' => 'Money in', 'out' => 'Money out'][$rule->direction] ?? 'In or out' }}</td>
                            <td>
                                <form method="POST" action="{{ route('rules.update', $rule) }}" class="d-flex gap-2">
                                    @csrf
                                    @method('PATCH')
                                    <select name="category" class="form-select form-select-sm" aria-label="Category for {{ $rule->keyword }}" data-autosubmit>
                                        <optgroup label="Categories">
                                            @foreach ($choices as $choice)
                                                <option value="type:{{ $choice['id'] }}" @selected($rule->category_type_id === $choice['id'])>{{ $choice['name'] }}</option>
                                            @endforeach
                                        </optgroup>
                                        <optgroup label="Every account">
                                            @foreach ($accounts as $account)
                                                <option value="{{ $account->id }}" @selected(! $rule->category_type_id && $rule->account_id === $account->id)>{{ $account->name }} ({{ $account->code }})</option>
                                            @endforeach
                                        </optgroup>
                                    </select>
                                </form>
                                @if (! $current)
                                    <div class="small text-danger mt-1">This rule has no category: pick one.</div>
                                @endif
                            </td>
                            <td class="text-end">
                                <form method="POST" action="{{ route('rules.destroy', $rule) }}" data-confirm="Transactions already in your books keep their category; new uploads are categorized as usual." data-confirm-title="Delete the rule for “{{ $rule->keyword }}”?" data-confirm-button="Delete rule" data-confirm-tone="danger">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-link text-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center py-5">
                                <p class="text-secondary mb-1">{{ $search !== '' ? 'No rule matches that search.' : 'No rules yet.' }}</p>
                                <p class="small text-secondary mb-0">On Check categories or Transactions, tick “Always” when you pick a category, and that merchant is categorized automatically from then on.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @include('partials.pagination', ['paginator' => $rules, 'label' => 'rules'])
@endsection

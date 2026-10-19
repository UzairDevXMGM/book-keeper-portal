@extends('layouts.app')

@section('title', 'Dump your data')

@php
    $statusBadges = [
        'uploaded' => ['Uploaded', 'text-bg-light border'],
        'processing' => ['Reading', 'text-bg-light border'],
        'needs_review' => ['Some need a category', 'text-bg-warning'],
        'completed' => ['Done', 'text-bg-primary'],
        'failed' => ["Couldn't read", 'text-bg-danger'],
    ];
@endphp

@section('content')
    <div class="page-head">
        <div>
            <h1 class="h3">Dump your data</h1>
            <div class="small text-secondary">Upload your bank and credit card statements. We read every transaction, give it a category and add it to your books.</div>
        </div>
        @if ($waiting)
            <a href="{{ route('review.index') }}" class="btn btn-sm btn-warning"><i class="bi bi-tags me-1"></i>{{ $waiting }} need a category</a>
        @endif
    </div>

    <div class="row g-3 mb-4">
        <div class="col-xl-8">
            <form method="POST" action="{{ route('uploads.store') }}" enctype="multipart/form-data" class="card h-100" novalidate>
                @csrf
                <div class="card-body p-4">
                    {{-- Step 1: year --}}
                    <div class="upload-step">
                        <div class="upload-step-head"><span class="upload-step-number">1</span> Which year are these statements for?</div>
                        <div class="d-flex flex-wrap gap-2" role="radiogroup" aria-label="Year">
                            @foreach ($years as $year)
                                <input type="radio" class="btn-check" name="year" id="year-{{ $year }}" value="{{ $year }}" autocomplete="off" @checked($selectedYear === $year)>
                                <label class="btn btn-sm btn-outline-primary px-3" for="year-{{ $year }}">{{ $year }}</label>
                            @endforeach
                        </div>
                        <div class="form-text">Only transactions dated in this year go into your books. Lines from other years are shown but left out.</div>
                        @error('year') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>

                    {{-- Step 2: account --}}
                    <div class="upload-step">
                        <div class="upload-step-head"><span class="upload-step-number">2</span> Which bank account or card is it from?</div>
                        <select id="account_id" name="account_id" class="form-select @error('account_id') is-invalid @enderror" aria-label="Bank account or card" required>
                            @foreach ($statementAccounts as $account)
                                <option value="{{ $account->id }}" @selected((int) old('account_id', request('account')) === $account->id)>
                                    {{ $account->name }}{{ $account->is_credit_card ? ' (credit card)' : '' }}
                                </option>
                            @endforeach
                        </select>
                        @error('account_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text">
                            Not in the list?
                            <a href="{{ route('accounts.index', ['new' => 1, 'kind' => 'bank']) }}">Add a bank account</a> or
                            <a href="{{ route('accounts.index', ['new' => 1, 'kind' => 'card']) }}">add a credit card</a>.
                        </div>
                    </div>

                    {{-- Step 3: file --}}
                    <div class="upload-step mb-0">
                        <div class="upload-step-head"><span class="upload-step-number">3</span> Add the statement file</div>
                        <label for="file" @class(['dropzone', 'is-invalid' => $errors->has('file')]) data-dropzone>
                            <input id="file" type="file" name="file" accept=".csv,.xlsx,.pdf,text/csv,application/pdf,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="visually-hidden" required>
                            <i class="bi bi-cloud-arrow-up"></i>
                            <span class="fw-semibold d-block" data-dropzone-label>Drop the file here or click to choose</span>
                            <span class="small text-secondary">PDF, Excel (.xlsx) or CSV · up to {{ $maxMb }} MB</span>
                        </label>
                        @error('file') <div class="text-danger small mt-1">{{ $message }}</div> @enderror

                        <div class="form-check mt-3">
                            <input type="hidden" name="auto_post" value="0">
                            <input class="form-check-input" type="checkbox" id="auto_post" name="auto_post" value="1" @checked(old('auto_post', '1'))>
                            <label class="form-check-label small" for="auto_post">Add transactions we are sure about to my books right away</label>
                        </div>

                        <details class="mt-2" @if ($errors->hasAny(['sign_convention', 'opening_balance', 'closing_balance'])) open @endif>
                            <summary class="small text-secondary">Amounts look wrong? More options</summary>
                            <div class="row g-2 mt-1">
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <select id="sign_convention" name="sign_convention" class="form-select">
                                            <option value="auto" @selected(old('sign_convention', 'auto') === 'auto')>Work it out from the file (best)</option>
                                            <option value="negative_is_out" @selected(old('sign_convention') === 'negative_is_out')>Money spent shows as a minus</option>
                                            <option value="positive_is_out" @selected(old('sign_convention') === 'positive_is_out')>Money spent shows without a minus</option>
                                        </select>
                                        <label for="sign_convention">How the file shows money spent</label>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="form-floating">
                                        <input id="opening_balance" name="opening_balance" value="{{ old('opening_balance') }}" inputmode="decimal" placeholder="0.00"
                                               class="form-control @error('opening_balance') is-invalid @enderror">
                                        <label for="opening_balance">Starting balance</label>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="form-floating">
                                        <input id="closing_balance" name="closing_balance" value="{{ old('closing_balance') }}" inputmode="decimal" placeholder="0.00"
                                               class="form-control @error('closing_balance') is-invalid @enderror">
                                        <label for="closing_balance">Ending balance</label>
                                    </div>
                                </div>
                            </div>
                            <p class="form-text mb-0">Type the starting and ending balance from the statement and we check that no transaction was missed.</p>
                        </details>
                    </div>
                </div>
                <div class="card-footer bg-white d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary">
                        <span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true"></span>
                        <i class="bi bi-upload me-1"></i>Upload and sort my transactions
                    </button>
                </div>
            </form>
        </div>

        <div class="col-xl-4">
            <div class="card h-100">
                <div class="card-body p-4">
                    <h2 class="card-title-sm mb-3">What happens next</h2>
                    <ol class="steps-list small">
                        <li>We read <strong>every transaction</strong> in the file.</li>
                        <li>Each one gets a <strong>category</strong>, like <em>Fuel</em> or <em>Meals &amp; Entertainment</em>: from 1,000+ known merchants, from the choices you made before, or by <strong>Smart Assist</strong>, which reads the description.</li>
                        <li>If we are not sure, the transaction waits in <a href="{{ route('review.index') }}">Check categories</a> with our best guess filled in.</li>
                        <li>Uploading the same transactions again never counts them twice.</li>
                    </ol>
                    <p class="small text-secondary mb-0">Tip: the CSV or Excel download from online banking is read most reliably. PDF statements work too.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header-flex flex-wrap gap-2">
            <h2 class="card-title-sm">Your uploads</h2>
            <ul class="nav nav-pills nav-pills-sm flex-wrap gap-1">
                <li class="nav-item"><a href="{{ route('uploads.index') }}" @class(['nav-link', 'active' => ! $yearFilter])>All years</a></li>
                @foreach (array_slice($years, 0, 4) as $year)
                    <li class="nav-item"><a href="{{ route('uploads.index', ['year' => $year]) }}" @class(['nav-link', 'active' => $yearFilter === $year])>{{ $year }}</a></li>
                @endforeach
            </ul>
        </div>
        <div class="table-responsive">
            <table class="table report-table table-hover mb-0">
                <thead>
                    <tr><th>File</th><th>Year</th><th>Bank / card</th><th>Dates</th><th class="num">Transactions</th><th class="num">In your books</th><th>Status</th><th>Uploaded</th></tr>
                </thead>
                <tbody>
                    @forelse ($uploads as $upload)
                        @php [$label, $badge] = $statusBadges[$upload->status] ?? [$upload->status, 'text-bg-light']; @endphp
                        <tr>
                            <td>
                                <a href="{{ route('uploads.show', $upload) }}" class="d-inline-flex align-items-center gap-2">
                                    <i class="bi bi-filetype-{{ $upload->file_type }} fs-5 text-secondary"></i>{{ \Illuminate\Support\Str::limit($upload->original_name, 40) }}
                                </a>
                            </td>
                            <td>{{ $upload->year ?? '—' }}</td>
                            <td class="text-nowrap">{{ $upload->account?->name }}</td>
                            <td class="text-nowrap small">
                                @if ($upload->statement_start_date)
                                    {{ $upload->statement_start_date->format('M j') }} – {{ $upload->statement_end_date?->format('M j, Y') }}
                                @else — @endif
                            </td>
                            <td class="num">{{ number_format($upload->rows_total) }}</td>
                            <td class="num">{{ number_format($upload->rows_posted) }}</td>
                            <td><span class="badge {{ $badge }}">{{ $label }}</span></td>
                            <td class="text-nowrap small">{{ $upload->created_at->format('M j, Y') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-secondary py-4">No statements uploaded{{ $yearFilter ? " for {$yearFilter}" : '' }} yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @include('partials.pagination', ['paginator' => $uploads, 'label' => 'uploads'])
@endsection

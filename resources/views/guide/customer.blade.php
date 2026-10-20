@extends('layouts.app')

@section('title', 'Guide')

@php
    $sections = [
        'start' => ['Getting started', 'flag'],
        'dashboard' => ['Dashboard', 'speedometer2'],
        'upload' => ['Dump your data', 'cloud-arrow-up'],
        'review' => ['Check categories', 'check2-square'],
        'rules' => ['My rules', 'magic'],
        'transactions' => ['Transactions', 'list-ul'],
        'ledger' => ['General Ledger', 'journal-text'],
        'reports' => ['Reports', 'file-earmark-bar-graph'],
        'accounts' => ['Chart of accounts', 'diagram-3'],
        'journal' => ['Journal entry', 'journal-plus'],
        'reconcile' => ['Reconcile', 'arrow-left-right'],
        'alerts' => ['Alerts', 'bell'],
        'profile' => ['Profile & billing', 'person'],
        'faq' => ['Common questions', 'question-circle'],
    ];
@endphp

@section('content')
    <div class="page-head">
        <div>
            <h1 class="h3">Guide</h1>
            <div class="small text-secondary">Everything your portal does, page by page, in plain words.</div>
        </div>
        <a href="{{ route('uploads.index') }}" class="btn btn-sm btn-primary"><i class="bi bi-cloud-arrow-up me-1"></i>Dump your data</a>
    </div>

    <div class="row g-4">
        <div class="col-lg-3 d-none d-lg-block">
            <nav class="guide-toc sticky-top" aria-label="Guide sections">
                @foreach ($sections as $id => [$label, $icon])
                    <a href="#{{ $id }}"><i class="bi bi-{{ $icon }}"></i>{{ $label }}</a>
                @endforeach
            </nav>
        </div>

        <div class="col-lg-9 guide">
            {{-- Small screens: the contents as chips --}}
            <div class="d-flex flex-wrap gap-1 mb-3 d-lg-none">
                @foreach ($sections as $id => [$label])
                    <a href="#{{ $id }}" class="badge rounded-pill text-bg-light border fw-normal text-decoration-none p-2">{{ $label }}</a>
                @endforeach
            </div>

            <section id="start" class="card mb-4">
                <div class="card-body p-4">
                    <h2 class="h5 mb-1"><i class="bi bi-flag me-2"></i>Getting started</h2>
                    <p class="text-secondary small">Your books are built from your bank and card statements. Four steps:</p>
                    <ol class="list-unstyled help-steps mb-0">
                        <li><div><div class="fw-semibold">Dump your data</div><div class="small text-secondary">Upload a bank or credit card statement. Pick its year and the account it is from.</div></div></li>
                        <li><div><div class="fw-semibold">We categorize every transaction</div><div class="small text-secondary">Walmart becomes Meals, a gas station Fuel, a payroll company Wages. We also read the kind of business in the name: “Eppies Restaurant” is a restaurant, “ABC Roofing Corp” a roofer. What we are sure about goes straight into your books.</div></div></li>
                        <li><div><div class="fw-semibold">Check the ones we were not sure about</div><div class="small text-secondary">They wait on Check categories with our best guess already picked. Approve or change it.</div></div></li>
                        <li><div><div class="fw-semibold">Read your reports</div><div class="small text-secondary">Profit &amp; Loss, Balance Sheet and the rest update by themselves.</div></div></li>
                    </ol>
                    <p class="small mt-3 mb-0">The <b>Get started</b> card on your <a href="{{ route('dashboard') }}">Dashboard</a> ticks these off as you go.</p>
                </div>
            </section>

            <section id="dashboard" class="card mb-4">
                <div class="card-body p-4">
                    <h2 class="h5 mb-2"><i class="bi bi-speedometer2 me-2"></i>Dashboard</h2>
                    <p class="small">Your business at a glance. The buttons at the top (<b>MTD</b> this month, <b>QTD</b> this quarter, <b>YTD</b> this year, <b>12M</b> last 12 months, <b>Last month</b>) change the dates of every number on the page.</p>
                    <ul class="small mb-0">
                        <li><b>Revenue, Net income, Cash on hand, Gross margin</b>, each compared with the same dates last year.</li>
                        <li><b>Revenue, costs and net income</b>: a chart of the last 12 months. “View as table” shows the numbers.</li>
                        <li><b>Alerts</b>: anything that needs your attention, such as transactions without a category.</li>
                        <li><b>Recent transactions</b> with their category, <b>Top expenses</b> and shortcuts to <b>your reports</b>.</li>
                        <li>Cards with a <i class="bi bi-lock"></i> lock (Financial health, Key metrics) come with an add-on report. The button on the card shows which one and its price.</li>
                    </ul>
                </div>
            </section>

            <section id="upload" class="card mb-4">
                <div class="card-body p-4">
                    <h2 class="h5 mb-2"><i class="bi bi-cloud-arrow-up me-2"></i>Dump your data</h2>
                    <ol class="small">
                        <li><b>Pick the year</b> the statement is for. Lines from another year (for example in a December–January statement) are shown as “Different year” and kept out; upload the file again with the other year to add them.</li>
                        <li><b>Pick the bank account or card</b> it is from. Not in the list? Use “Add a bank account” or “add a credit card”.</li>
                        <li><b>Drop the file</b>: PDF, Excel (.xlsx) or CSV, up to {{ $maxMb }} MB. The CSV or Excel download from online banking is read most reliably.</li>
                    </ol>
                    <p class="small">Keep <b>“Add transactions we are sure about to my books right away”</b> ticked. If amounts come out the wrong way round, open <b>“Amounts look wrong? More options”</b>.</p>
                    <p class="small mb-1">After the upload you see every line with its category and one of these:</p>
                    <ul class="small mb-0">
                        <li><b>In your books</b>: done.</li>
                        <li><b>Check category</b>: waiting for you on Check categories.</li>
                        <li><b>Already uploaded</b>: this transaction is in your books from an earlier file, so it is not counted twice.</li>
                        <li><b>Different year</b>: dated outside the year you picked.</li>
                        <li><b>Couldn't read</b> / <b>Skipped</b>: a line we could not use, with the reason.</li>
                    </ul>
                </div>
            </section>

            <section id="review" class="card mb-4">
                <div class="card-body p-4">
                    <h2 class="h5 mb-2"><i class="bi bi-check2-square me-2"></i>Check categories</h2>
                    <p class="small">Transactions we were less than {{ $threshold }}% sure about wait here. Each shows <b>our guess</b> and how we got it (a known merchant, your past choices, the words in the description).</p>
                    <ul class="small mb-0">
                        <li>Keep or change the category and press <b>Approve</b>.</li>
                        <li>Tick <b>“Always for …”</b> and that merchant is categorized automatically from now on. <b>“+3 like this”</b> means three more lines from the same merchant are waiting: one “Always” does them all.</li>
                        <li><b>Group by merchant</b> puts lines from the same merchant together. Tick several and use <b>Approve ticked</b> or <b>Skip ticked</b>.</li>
                        <li><b>In your books without a category</b> (bottom of the page): amounts already added as “Uncategorized”. Pick a category and press Move.</li>
                    </ul>
                </div>
            </section>

            <section id="rules" class="card mb-4">
                <div class="card-body p-4">
                    <h2 class="h5 mb-2"><i class="bi bi-magic me-2"></i>My rules</h2>
                    <p class="small mb-0">Every merchant you ticked “Always” for. Change its category from the list, or press <b>Delete</b> if you ticked it by mistake. Transactions already in your books keep their category; the rule is used for new uploads.</p>
                </div>
            </section>

            <section id="transactions" class="card mb-4">
                <div class="card-body p-4">
                    <h2 class="h5 mb-2"><i class="bi bi-list-ul me-2"></i>Transactions</h2>
                    <ul class="small mb-0">
                        <li><b>Pick the dates first</b> (this month, last quarter, this year, custom…), then the list loads.</li>
                        <li><b>Simple</b> view: one row per transaction with the <b>merchant</b> and the kind of business it is, its <b>category</b> (every category when a payment is split, like wages and payroll taxes), the <b>bank or card</b> and <b>money in / out</b>. Transfers between your own accounts and starting balances are not counted as money in or out.</li>
                        <li><b>Wrong category?</b> Click the category, pick the right one and press <b>Save category</b>. Your books stay correct: a small correcting entry is added on the same date.</li>
                        <li>Search, dates, “Account or category” and “Came from” filter the list. The search box at the top of every page searches here too.</li>
                        <li><b>Accountant view</b> shows every debit and credit line, for your accountant.</li>
                    </ul>
                </div>
            </section>

            <section id="ledger" class="card mb-4">
                <div class="card-body p-4">
                    <h2 class="h5 mb-2"><i class="bi bi-journal-text me-2"></i>General Ledger</h2>
                    <p class="small mb-0">Every account with every transaction in it, its opening balance, a running balance and the closing balance. Pick the dates first. Filter by one account, by bank or card, or search a description. Your accountant will ask for it at year end: use <b>CSV</b> or <b>Print / PDF</b>.</p>
                </div>
            </section>

            <section id="reports" class="card mb-4">
                <div class="card-body p-4">
                    <h2 class="h5 mb-2"><i class="bi bi-file-earmark-bar-graph me-2"></i>Reports</h2>
                    <p class="small">Every report has a <b>Period</b> (this month, last quarter, last 12 months, custom dates…). Many also have:</p>
                    <ul class="small">
                        <li><b>Bank / card</b>: only what went through one account (for example your business card).</li>
                        <li><b>Search</b>, <b>Category</b>, <b>Type</b> or <b>Account</b>, depending on the report. Active filters show as chips; ✕ removes one, <b>Reset</b> removes all.</li>
                        <li><b>CSV</b> downloads the report for Excel; <b>Print / PDF</b> opens a clean page to print or save as PDF. The filters you used are written at the top.</li>
                    </ul>
                    <p class="small mb-2">Included in your {{ $plan?->name }} plan:</p>
                    <ul class="list-unstyled small">
                        @foreach ($included as $report)
                            <li class="mb-2"><a href="{{ route('reports.show', $report->code) }}" class="fw-semibold">{{ $report->name }}</a> · <span class="text-secondary">{{ $report->description }}</span></li>
                        @endforeach
                    </ul>
                    @if ($addons->isNotEmpty())
                        <p class="small mb-2">Add-ons (monthly, added to your plan, cancel any time):</p>
                        <ul class="list-unstyled small mb-0">
                            @foreach ($addons as $report)
                                <li class="mb-2">
                                    <a href="{{ route('reports.show', $report->code) }}" class="fw-semibold">{{ $report->name }}</a>
                                    <span class="badge rounded-pill text-bg-light border">{{ in_array($report->id, $owned, true) ? 'Active' : money($report->addon_price).'/mo' }}</span>
                                    · <span class="text-secondary">{{ $report->description }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </section>

            <section id="accounts" class="card mb-4">
                <div class="card-body p-4">
                    <h2 class="h5 mb-2"><i class="bi bi-diagram-3 me-2"></i>Chart of accounts</h2>
                    <p class="small mb-0">The list of accounts your books use, set up for your industry. By default you see <b>only the accounts you use</b>; switch it off or search to see them all. <b>Add account</b> creates a new one; <b>Edit</b> renames or hides one. Click an account name to see every transaction in it.</p>
                </div>
            </section>

            <section id="journal" class="card mb-4">
                <div class="card-body p-4">
                    <h2 class="h5 mb-2"><i class="bi bi-journal-plus me-2"></i>Journal entry</h2>
                    <p class="small mb-0">For anything that is not on a statement: starting balances, cash you paid, depreciation, loans. Each line goes to one account as a debit or a credit, and the two sides must be equal. A saved entry is never edited; open it and press <b>Reverse entry</b> to cancel it, then post a new one. Most people rarely need this page.</p>
                </div>
            </section>

            <section id="reconcile" class="card mb-4">
                <div class="card-body p-4">
                    <h2 class="h5 mb-2"><i class="bi bi-arrow-left-right me-2"></i>Reconcile</h2>
                    <p class="small mb-0">Proves your books match the bank. Pick the account and the statement's ending date and balance (or start from an uploaded statement), then tick the transactions that appear on the statement until the difference is <b>$0.00</b>. Finish it, and you can lock the books up to that date so nothing changes them by mistake.</p>
                </div>
            </section>

            <section id="alerts" class="card mb-4">
                <div class="card-body p-4">
                    <h2 class="h5 mb-2"><i class="bi bi-bell me-2"></i>Alerts</h2>
                    <p class="small mb-0">Checked against your books every time you open it: transactions without a category, a negative cash balance, a loss this year, books that are out of date. Each alert says what to do. The red number on the bell at the top shows how many there are.</p>
                </div>
            </section>

            <section id="profile" class="card mb-4">
                <div class="card-body p-4">
                    <h2 class="h5 mb-2"><i class="bi bi-person me-2"></i>Profile &amp; billing</h2>
                    <ul class="small mb-0">
                        <li><b>Picture</b>: “Add photo” / “Change photo” under your picture (JPG, PNG or WebP up to 2 MB).</li>
                        <li><b>Personal information</b>: your name and the email you log in with.</li>
                        <li><b>Business details</b>: business name (shown on reports and invoices), type, industry, state, the month your fiscal year starts, cash or accrual.</li>
                        <li><b>Password</b>: type the current one and the new one twice.</li>
                        <li><b>Plan &amp; billing</b>: your plan, add-ons, card and every invoice. Open or print an invoice, change your card, or cancel the plan right here.</li>
                    </ul>
                </div>
            </section>

            <section id="faq" class="card mb-4">
                <div class="card-body p-4 help-faq">
                    <h2 class="h5 mb-2"><i class="bi bi-question-circle me-2"></i>Common questions</h2>
                    <details>
                        <summary>What if I upload the same statement twice?</summary>
                        <p class="small">Nothing is counted twice. Transactions already in your books are recognised and skipped, even when two statements overlap.</p>
                    </details>
                    <details>
                        <summary>My PDF could not be read.</summary>
                        <p class="small">It is probably a scan or photo. Download the statement again from online banking, ideally as CSV or Excel.</p>
                    </details>
                    <details>
                        <summary>A transaction has the wrong category.</summary>
                        <p class="small">Open <a href="{{ route('transactions.index') }}">Transactions</a>, click the category and pick the right one. Tick “Always use this category” to fix that merchant for the future too.</p>
                    </details>
                    <details>
                        <summary>I ticked “Always” by mistake.</summary>
                        <p class="small">Open <a href="{{ route('rules.index') }}">My rules</a> and change or delete it.</p>
                    </details>
                    <details>
                        <summary>Money moved between my own accounts shows up. Is that income?</summary>
                        <p class="small">No. Transfers between your accounts and credit card payments are recorded as “Transfer between your accounts” and are not counted as income or spending.</p>
                    </details>
                    <details>
                        <summary>What does “Uncategorized” mean?</summary>
                        <p class="small">We could not tell what the transaction was, so it is in your books as “Uncategorized” until you pick a category on <a href="{{ route('review.index') }}">Check categories</a>.</p>
                    </details>
                    <details>
                        <summary>Is my data safe?</summary>
                        <p class="small">Your statements and books are only visible to you. Files are stored privately, the site uses a secure connection, and card details are handled by Stripe and never touch our server.</p>
                    </details>
                </div>
            </section>

            @if ($support)
                <div class="card">
                    <div class="card-body p-4 d-flex flex-wrap align-items-center gap-3">
                        <div class="me-auto">
                            <h2 class="h6 mb-1">Still stuck?</h2>
                            <p class="small text-secondary mb-0">Send us an email and we will help you.</p>
                        </div>
                        <a href="mailto:{{ $support }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-envelope me-1"></i>{{ $support }}</a>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection

@extends('layouts.app')

@section('title', $report->name)

@php
    // The preview never sends the add-on's numbers to the browser (they could be read from the page source):
    // labels and account names are shown, every value is masked
    $fmt = fn ($value, string $format) => match (true) {
        $value === null || $value === '' => '',
        $format === 'money' => '$ •••••',
        in_array($format, ['pct', 'percent'], true) => '•• %',
        $format === 'number' => '•••',
        default => is_numeric($value) ? '•••••' : $value,
    };
    $onSale = (bool) $report->stripe_price_id;
@endphp

@section('content')
    <a href="{{ route('reports.index') }}" class="small"><i class="bi bi-arrow-left me-1"></i>Reports</a>

    <div class="row g-4 mt-0">
        {{-- What the report is --}}
        <div class="col-lg-8">
            <div class="addon-hero card mb-4">
                <div class="card-body p-4">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                        <span class="badge rounded-pill addon-badge"><i class="bi bi-stars me-1"></i>Add-on report</span>
                        @if ($report->category)
                            <span class="badge rounded-pill text-bg-light border fw-normal">{{ $report->category }}</span>
                        @endif
                    </div>
                    <h1 class="h3 mb-2">{{ $report->name }}</h1>
                    <p class="text-secondary mb-0">{{ $report->description }}</p>
                </div>
            </div>

            @if ($preview && ($preview['kpis'] || $preview['rows']))
                <div class="card mb-4">
                    <div class="card-header-flex">
                        <div>
                            <h2 class="card-title-sm">Preview with your books</h2>
                            <div class="small text-secondary">Fiscal year to date · your numbers are ready as soon as you add it</div>
                        </div>
                    </div>
                    <div class="card-body pt-0">
                        <div class="addon-preview">
                            <div class="addon-preview-content" aria-hidden="true">
                                @if ($preview['kpis'])
                                    <div class="row row-cols-2 row-cols-md-4 g-2 mb-3">
                                        @foreach ($preview['kpis'] as $kpi)
                                            <div class="col">
                                                <div class="kpi-card">
                                                    <div class="kpi-label">{{ $kpi['label'] }}</div>
                                                    <div class="kpi-value h5 mb-0">{{ $kpi['format'] === 'text' ? '•••••' : $fmt($kpi['value'], $kpi['format']) }}</div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                                @if ($preview['rows'])
                                    <table class="table report-table table-sm mb-0">
                                        <thead><tr>@foreach ($preview['columns'] as $column)<th @class(['num' => $column['format'] !== 'text'])>{{ $column['label'] }}</th>@endforeach</tr></thead>
                                        <tbody>
                                            @foreach ($preview['rows'] as $row)
                                                <tr>@foreach ($preview['columns'] as $column)<td @class(['num' => $column['format'] !== 'text'])>{{ $loop->first ? ($row['cells'][$column['key']] ?? '') : (($row['cells'][$column['key']] ?? null) === null ? '' : ($column['format'] === 'text' ? '•••••' : $fmt($row['cells'][$column['key']], $column['format']))) }}</td>@endforeach</tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                @endif
                            </div>
                            <div class="addon-preview-lock">
                                <span class="addon-lock-icon"><i class="bi bi-lock"></i></span>
                                <div class="fw-semibold">Unlock {{ $report->name }}</div>
                                <div class="small text-secondary">See every number from your own books, print it or export it.</div>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            <div class="card mb-4">
                <div class="card-body p-4">
                    <h2 class="h6 mb-3">What you get</h2>
                    <div class="row row-cols-1 row-cols-md-2 g-3 small">
                        <div class="col d-flex gap-2"><i class="bi bi-arrow-repeat text-primary"></i><div><b>Always up to date.</b> Built from your books every time you open it.</div></div>
                        <div class="col d-flex gap-2"><i class="bi bi-calendar3 text-primary"></i><div><b>Any dates.</b> This month, last quarter, last year or your own dates.</div></div>
                        <div class="col d-flex gap-2"><i class="bi bi-printer text-primary"></i><div><b>Print, PDF and CSV.</b> Ready for your accountant, bank or investors.</div></div>
                        <div class="col d-flex gap-2"><i class="bi bi-x-circle text-primary"></i><div><b>No contract.</b> Remove it any time from My profile → Plan &amp; billing. Payments are non-refundable.</div></div>
                    </div>
                </div>
            </div>

            @if ($related->isNotEmpty())
                <h2 class="h6 mb-2">Other {{ strtolower($report->category ?? 'add-on') }} add-ons</h2>
                <div class="row row-cols-1 row-cols-md-3 g-3">
                    @foreach ($related as $other)
                        <div class="col">
                            <a href="{{ route('reports.show', $other->code) }}" class="card h-100 text-decoration-none text-body addon-related">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between gap-2">
                                        <span class="fw-semibold">{{ $other->name }}</span>
                                        <span class="small text-secondary text-nowrap">{{ money($other->addon_price) }}/mo</span>
                                    </div>
                                    <div class="small text-secondary mt-1">{{ \Illuminate\Support\Str::limit($other->description, 90) }}</div>
                                </div>
                            </a>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Price and what changes on the bill --}}
        <div class="col-lg-4">
            <div class="card addon-order sticky-top">
                <div class="card-body p-4">
                    <div class="d-flex align-items-baseline gap-1 mb-1">
                        <span class="plan-price" style="font-size: 2.4rem">{{ money($quote['price']) }}</span>
                        <span class="text-secondary">/month</span>
                    </div>
                    <div class="small text-secondary mb-3">Added to your {{ $quote['plan_name'] }} plan · cancel any time</div>

                    <dl class="addon-summary small mb-3">
                        <div><dt>{{ $quote['plan_name'] }} plan</dt><dd>{{ money($quote['plan_price']) }}</dd></div>
                        @if ($quote['addons_price'] > 0)
                            <div><dt>Your other add-ons</dt><dd>{{ money($quote['addons_price']) }}</dd></div>
                        @endif
                        <div><dt>{{ $report->name }}</dt><dd>{{ money($quote['price']) }}</dd></div>
                        <div class="addon-summary-total"><dt>New monthly total</dt><dd>{{ money($quote['new_monthly']) }}</dd></div>
                    </dl>

                    @if ($onSale)
                        <button type="button" class="btn btn-primary btn-lg w-100" data-bs-toggle="modal" data-bs-target="#buyAddonModal">
                            <i class="bi bi-plus-lg me-1"></i>Add to my plan
                        </button>
                        <ul class="list-unstyled small text-secondary mt-3 mb-0 addon-assurance">
                            <li><i class="bi bi-lightning-charge"></i>Opens straight away</li>
                            <li><i class="bi bi-credit-card"></i>{{ $quote['card'] ? 'Charged to '.$quote['card'] : 'Charged to the card on your plan' }}</li>
                            <li><i class="bi bi-shield-lock"></i>Payment handled securely by Stripe</li>
                            <li><i class="bi bi-info-circle"></i>Non-refundable · remove it any time to stop future charges</li>
                        </ul>
                    @else
                        <div class="alert alert-secondary small mb-0">This add-on is not on sale yet. Please check back soon.</div>
                    @endif
                </div>
            </div>
            <div class="small text-secondary mt-3 px-1">Your plan already includes {{ $includedCount }} reports. <a href="{{ route('reports.index') }}">See all reports</a></div>
        </div>
    </div>

    @if ($onSale)
        {{-- Order confirmation --}}
        <div class="modal fade" id="buyAddonModal" tabindex="-1" aria-labelledby="buyAddonTitle" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form method="POST" action="{{ route('addons.store', $report->code) }}" class="modal-content addon-modal"
                      data-addon-form data-js-submit data-key="{{ config('cashier.key') }}" data-confirm-url="{{ route('addons.confirm', $report->code) }}">
                    @csrf
                    <div class="modal-header border-0 pb-0">
                        <div class="d-flex align-items-center gap-3">
                            <span class="addon-modal-icon"><i class="bi bi-bag-check"></i></span>
                            <div>
                                <h2 class="modal-title h5 mb-0" id="buyAddonTitle">Confirm your add-on</h2>
                                <div class="small text-secondary">{{ $report->name }}</div>
                            </div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <dl class="addon-summary small mb-3">
                            <div><dt>{{ $report->name }}</dt><dd>{{ money($quote['price']) }}/month</dd></div>
                            <div><dt>Due today</dt><dd>{{ $quote['due_today'] !== null ? money($quote['due_today']) : 'a part of '.money($quote['price']) }}</dd></div>
                            <div><dt>Next invoice{{ $quote['next_billing'] ? ', '.$quote['next_billing']->format('M j, Y') : '' }}</dt><dd>{{ money($quote['new_monthly']) }}</dd></div>
                            <div><dt>Payment method</dt><dd>{{ $quote['card'] ?? 'Card on your plan' }}</dd></div>
                        </dl>
                        <div class="addon-note small">
                            <i class="bi bi-info-circle me-1"></i>
                            Today you pay only for the days left in this billing month{{ $quote['next_billing'] ? ' (until '.$quote['next_billing']->format('M j').')' : '' }}.
                            From then on it is {{ money($quote['price']) }} a month with your plan.
                            <b>Payments are non-refundable.</b> If you remove it later, it stops renewing and stays open until the end of the month already paid.
                        </div>
                        <div class="alert alert-danger small mt-3 mb-0 d-none" role="alert" data-addon-message></div>
                    </div>
                    <div class="modal-footer border-0 pt-0">
                        <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Not now</button>
                        <button type="submit" class="btn btn-primary" data-addon-button>
                            <span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true" data-addon-spinner></span>
                            {{ $quote['due_today'] !== null ? 'Confirm and pay '.money($quote['due_today']) : 'Confirm and add' }}
                        </button>
                    </div>
                    <div class="addon-modal-foot small text-secondary"><i class="bi bi-shield-lock me-1"></i>Secure payment by Stripe. Your card details never touch our server.</div>
                </form>
            </div>
        </div>
        <script src="https://js.stripe.com/v3/"></script>
        <script src="{{ asset('js/billing.js') }}?v={{ @filemtime(public_path('js/billing.js')) ?: '1' }}" defer></script>
    @endif
@endsection

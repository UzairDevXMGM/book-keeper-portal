@extends('layouts.app')

@section('title', $company->name)

@section('content')
    <a href="{{ route('admin.customers.index') }}" class="small"><i class="bi bi-arrow-left me-1"></i>Customers</a>

    <div class="card profile-hero my-3">
        <div class="card-body d-flex flex-wrap align-items-center gap-3">
            <x-avatar :name="$company->name" size="xl" square />
            <div class="me-auto">
                <h1 class="h3 mb-0">{{ $company->name }}</h1>
                <div class="text-secondary small">
                    {{ collect([$company->industry, config('business.types.'.$company->business_type), $company->city, $company->state])->filter()->implode(' · ') }}
                </div>
                <div class="small mt-1">
                    Owner: <strong>{{ $company->owner?->name }}</strong> · {{ $company->owner?->email }} · {{ $company->phone ?? 'no phone' }}
                    @if ($company->owner?->last_login_at) · last login {{ $company->owner->last_login_at->diffForHumans() }} @endif
                </div>
            </div>
            <form method="POST" action="{{ route('admin.customers.access', $company) }}"
                  data-confirm="{{ $company->owner?->is_active ? $company->owner?->email.' is signed out and cannot log in. Their books are kept.' : $company->owner?->email.' can log in again.' }}" data-confirm-title="{{ $company->owner?->is_active ? 'Block this login?' : 'Allow this login?' }}" data-confirm-button="{{ $company->owner?->is_active ? 'Block login' : 'Allow login' }}" @if ($company->owner?->is_active) data-confirm-tone="danger" @endif>
                @csrf
                @if ($company->owner?->is_active)
                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-person-slash me-1"></i>Disable login</button>
                @else
                    <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-person-check me-1"></i>Enable login</button>
                @endif
            </form>
        </div>
    </div>

    <div class="row row-cols-2 row-cols-lg-4 g-2 g-md-3 mb-4">
        <div class="col"><x-kpi icon="box-seam" label="Plan" :value="($plan?->name ?? '—').($subscription ? ' · '.ucfirst($subscription->stripe_status) : '')" /></div>
        <div class="col"><x-kpi icon="journal-text" label="Journal entries" :value="number_format($stats['entries'])" /></div>
        <div class="col"><x-kpi icon="cloud-arrow-up" label="Uploads" :value="number_format($stats['uploads'])" :sub="$stats['review'] ? $stats['review'].' lines waiting for review' : null" /></div>
        <div class="col"><x-kpi icon="calendar3" label="Latest transaction" :value="$stats['last_entry'] ? \Carbon\CarbonImmutable::parse($stats['last_entry'])->format('M j, Y') : '—'" /></div>
    </div>

    <div class="row g-3">
        <div class="col-xl-6">
            <div class="card h-100">
                <div class="card-header-flex"><h2 class="card-title-sm">Report access</h2></div>
                <div class="table-responsive">
                    <table class="table report-table mb-0">
                        <thead><tr><th>Report</th><th>Access</th></tr></thead>
                        <tbody>
                            @foreach ($reports as $item)
                                <tr>
                                    <td>{{ $item['report']->name }} <span class="small text-secondary">{{ $item['report']->code }}</span></td>
                                    <td>
                                        @if ($item['included'])
                                            <span class="badge text-bg-primary">In plan</span>
                                        @elseif ($item['purchased'])
                                            <span class="badge text-bg-dark">Add-on</span>
                                        @else
                                            <span class="badge text-bg-light border">Locked</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="card-body border-top">
                    <form method="POST" action="{{ route('admin.customers.grant', $company) }}" class="d-flex gap-2">
                        @csrf
                        <select name="report_id" class="form-select form-select-sm" aria-label="Report to give for free" required>
                            @foreach ($reports->reject(fn ($r) => $r['has_access']) as $item)
                                <option value="{{ $item['report']->id }}">{{ $item['report']->name }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap">Give for free</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-xl-6">
            <div class="card mb-3">
                <div class="card-header-flex"><h2 class="card-title-sm">Add-ons</h2></div>
                <div class="table-responsive">
                    <table class="table report-table mb-0">
                        <thead><tr><th>Report</th><th class="num">Price</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            @forelse ($addons as $addon)
                                <tr>
                                    <td>{{ $addon->report->name }}</td>
                                    <td class="num">{{ $addon->price > 0 ? money($addon->price).'/mo' : 'Free' }}</td>
                                    <td><span class="badge {{ $addon->status === 'active' ? 'text-bg-primary' : 'text-bg-light border' }}">{{ ucfirst($addon->status) }}</span></td>
                                    <td class="text-end">
                                        @if ($addon->status === 'active' && ! $addon->stripe_subscription_item_id)
                                            <form method="POST" action="{{ route('admin.customers.revoke', [$company, $addon]) }}" data-confirm="The business loses access to {{ $addon->report->name }} now." data-confirm-title="Remove this add-on?" data-confirm-button="Remove add-on" data-confirm-tone="danger">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-link text-danger p-0">Remove</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-secondary py-3">No add-ons.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header-flex">
                    <h2 class="card-title-sm">Recent activity</h2>
                    <a href="{{ route('admin.audit.index', ['company' => $company->id]) }}" class="small">Full log</a>
                </div>
                <ul class="list-unstyled mb-0 px-3 pb-3">
                    @forelse ($activity as $log)
                        <li class="small py-1 border-bottom">
                            <code>{{ $log->action }}</code>
                            <span class="text-secondary">· {{ $log->user?->email ?? 'system' }} · {{ $log->created_at->diffForHumans() }}</span>
                        </li>
                    @empty
                        <li class="small text-secondary">No activity recorded yet.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
@endsection

@php
    // [label, icon, route name (null = not built yet), route pattern for the active state, "soon" badge]
    $sections = $layoutUser->isAdmin()
        ? [
            'Admin' => [
                ['Overview', 'grid-1x2', 'admin.overview', 'admin.overview'],
                ['Customers', 'people', 'admin.customers.index', 'admin.customers.*'],
                ['Plans', 'box-seam', 'admin.plans.index', 'admin.plans.*'],
                ['Report catalog', 'journal-richtext', 'admin.reports.index', 'admin.reports.*'],
                ['Categories', 'tags', 'admin.categories.index', 'admin.categories.*'],
                ['Users', 'person-gear', 'admin.users.index', 'admin.users.*'],
                ['Audit log', 'clock-history', 'admin.audit.index', 'admin.audit.*'],
                ['Admin guide', 'book', 'admin.guide', 'admin.guide'],
                ['Settings', 'gear', 'admin.settings.show', 'admin.settings.*'],
            ],
            'Account' => [
                ['My profile', 'person', 'profile.show', 'profile.*'],
            ],
        ]
        : [
            'Workspace' => [
                ['Dashboard', 'speedometer2', 'dashboard', 'dashboard'],
                ['Reports', 'file-earmark-bar-graph', 'reports.index', 'reports.*'],
                ['Transactions', 'list-ul', 'transactions.index', 'transactions.*'],
                ['Chart of accounts', 'diagram-3', 'accounts.index', 'accounts.*'],
                ['Alerts', 'bell', 'alerts.index', 'alerts.*'],
            ],
            'Bookkeeping' => [
                ['Dump your data', 'cloud-arrow-up', 'uploads.index', 'uploads.*'],
                ['Check categories', 'check2-square', 'review.index', 'review.*'],
                ['My rules', 'magic', 'rules.index', 'rules.*'],
                ['Journal entry', 'journal-plus', 'journal.create', 'journal.*'],
                ['Reconcile', 'arrow-left-right', 'reconcile.index', 'reconcile.*'],
            ],
            'Account' => [
                ['My profile', 'person', 'profile.show', 'profile.*'],
                ['Guide', 'book', 'help', 'help'],
            ],
        ];
@endphp

<aside class="offcanvas-lg offcanvas-start app-sidebar" tabindex="-1" id="appSidebar" aria-label="Main menu">
    <div class="sidebar-inner">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <a href="{{ route('dashboard') }}" class="sidebar-brand">
                <img src="{{ asset('images/logo.png') }}" alt="BookKeepingLLC">
            </a>
            <button type="button" class="btn-close btn-close-white d-lg-none" data-bs-dismiss="offcanvas" data-bs-target="#appSidebar" aria-label="Close menu"></button>
        </div>

        @if ($layoutCompany)
            <a href="{{ route('profile.show') }}#business" class="sidebar-company">
                <x-avatar :name="$layoutCompany->name" size="md" square />
                <span class="min-w-0">
                    <span class="d-block fw-semibold text-truncate">{{ $layoutCompany->name }}</span>
                    <span class="d-block small text-truncate">
                        <span @class(['status-dot', 'is-active' => in_array($layoutPlanStatus, ['active', 'trialing'], true)])></span>
                        {{ $layoutPlan?->name ?? 'No' }} plan{{ $layoutPlanStatus ? ' · '.ucfirst(str_replace('_', ' ', $layoutPlanStatus)) : '' }}
                    </span>
                </span>
            </a>
        @endif

        <nav>
            @foreach ($sections as $title => $items)
                <div class="sidebar-section">{{ $title }}</div>
                @foreach ($items as $item)
                    @php [$label, $icon, $route, $pattern] = $item; $soon = $item[4] ?? false; @endphp
                    @if ($route)
                        <a href="{{ route($route) }}" @class(['sidebar-link', 'active' => request()->routeIs($pattern)])
                           @if (request()->routeIs($pattern)) aria-current="page" @endif>
                            <i class="bi bi-{{ $icon }}"></i><span>{{ $label }}</span>
                            @if ($soon)<span class="badge-soon">Soon</span>@endif
                            @if ($route === 'review.index' && $layoutReviewCount)
                                <span class="badge rounded-pill text-bg-warning ms-auto" title="{{ $layoutReviewCount }} waiting">{{ $layoutReviewCount > 99 ? '99+' : $layoutReviewCount }}</span>
                            @endif
                        </a>
                    @else
                        <span class="sidebar-link disabled" aria-disabled="true">
                            <i class="bi bi-{{ $icon }}"></i><span>{{ $label }}</span><span class="badge-soon">Soon</span>
                        </span>
                    @endif
                @endforeach
            @endforeach
        </nav>

        @if ($layoutCompany)
            <div class="sidebar-promo">
                <div class="fw-semibold text-white mb-1"><i class="bi bi-stars me-1 text-primary"></i>More insight</div>
                <p class="small mb-2">Ratios, trends and spending reports are available as add-ons.</p>
                <a href="{{ route('reports.index') }}" class="btn btn-sm btn-primary w-100">Browse reports</a>
            </div>
        @endif
    </div>
</aside>

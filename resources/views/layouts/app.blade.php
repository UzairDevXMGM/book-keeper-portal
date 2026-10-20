<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · {{ config('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('images/favicon-32.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    @include('layouts.partials.assets')
</head>
<body>
    @php
        $statusMessages = [
            'profile-information-updated' => 'Your profile was updated.',
            'password-updated' => 'Your password was changed.',
        ];
        $status = session('status');
    @endphp

    <a href="#main" class="visually-hidden-focusable skip-link">Skip to content</a>

    <div class="app-shell">
        @include('layouts.partials.sidebar')

        <div class="app-main">
            {{-- Header --}}
            <header class="app-topbar">
                <button class="btn btn-icon d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#appSidebar" aria-controls="appSidebar" aria-label="Open menu">
                    <i class="bi bi-list fs-5"></i>
                </button>

                @if ($layoutCompany)
                    <form action="{{ route('transactions.index') }}" method="GET" class="topbar-search d-none d-md-block" role="search">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input type="search" name="q" value="{{ request()->routeIs('transactions.*') ? request('q') : '' }}"
                               class="form-control form-control-sm" placeholder="Search transactions..." aria-label="Search transactions">
                    </form>
                @else
                    <span class="fw-semibold">Platform admin</span>
                @endif

                <div class="ms-auto d-flex align-items-center gap-1 gap-sm-2">
                    @if ($layoutCompany)
                        <div class="dropdown">
                            <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-plus-lg"></i><span class="d-none d-sm-inline ms-1">New</span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="{{ route('journal.create') }}"><i class="bi bi-journal-plus"></i>Journal entry</a></li>
                                <li><a class="dropdown-item" href="{{ route('uploads.index') }}"><i class="bi bi-cloud-arrow-up"></i>Dump your data</a></li>
                                <li><a class="dropdown-item" href="{{ route('accounts.index', ['new' => 1]) }}"><i class="bi bi-diagram-3"></i>Account</a></li>
                            </ul>
                        </div>

                        <a href="{{ route('alerts.index') }}" class="btn btn-icon position-relative"
                           aria-label="Alerts{{ $layoutAlertCount ? " ({$layoutAlertCount} open)" : '' }}">
                            <i class="bi bi-bell fs-5"></i>
                            @if ($layoutAlertCount)
                                <span class="topbar-badge">{{ $layoutAlertCount > 9 ? '9+' : $layoutAlertCount }}</span>
                            @endif
                        </a>
                    @endif

                    <div class="dropdown">
                        <button class="btn user-menu-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account menu">
                            <x-avatar :user="$layoutUser" />
                            <span class="d-none d-md-block text-start lh-sm">
                                <span class="d-block fw-semibold small">{{ $layoutUser->name }}</span>
                                <span class="d-block text-secondary" style="font-size: .72rem">{{ $layoutUser->isAdmin() ? 'Administrator' : 'Business owner' }}</span>
                            </span>
                            <i class="bi bi-chevron-down small text-secondary d-none d-md-block"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end user-menu">
                            <div class="d-flex align-items-center gap-2 px-3 py-2">
                                <x-avatar :user="$layoutUser" size="md" />
                                <div class="min-w-0">
                                    <div class="fw-semibold text-truncate">{{ $layoutUser->name }}</div>
                                    <div class="small text-secondary text-truncate">{{ $layoutUser->email }}</div>
                                </div>
                            </div>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item" href="{{ route('profile.show') }}"><i class="bi bi-person"></i>My profile</a>
                            @if ($layoutCompany)
                                <a class="dropdown-item" href="{{ route('profile.show') }}#business"><i class="bi bi-building"></i>Business details</a>
                                @if ($layoutCompany->hasStripeId())
                                    <a class="dropdown-item" href="{{ route('billing.portal') }}"><i class="bi bi-credit-card"></i>Billing &amp; invoices</a>
                                @endif
                            @endif
                            <a class="dropdown-item" href="{{ $websiteUrl }}/contact.html" target="_blank" rel="noopener"><i class="bi bi-life-preserver"></i>Help &amp; support</a>
                            <div class="dropdown-divider"></div>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="dropdown-item text-danger"><i class="bi bi-box-arrow-right"></i>Log out</button>
                            </form>
                        </div>
                    </div>
                </div>
            </header>

            <main id="main" class="app-content">
                @if ($status)
                    <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2" role="status">
                        <i class="bi bi-check-circle-fill"></i>
                        <span>{{ $statusMessages[$status] ?? $status }}</span>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                @endif
                @if (session('error'))
                    <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2" role="alert">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <span>{{ session('error') }}</span>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                @endif

                @yield('content')
            </main>

            {{-- Footer --}}
            <footer class="app-footer no-print">
                <span>&copy; {{ date('Y') }} BookKeepingLLC. All rights reserved.</span>
                <nav class="d-flex flex-wrap gap-3" aria-label="Footer">
                    <a href="{{ $websiteUrl }}" target="_blank" rel="noopener">Website</a>
                    <a href="{{ $websiteUrl }}/terms-of-service.html" target="_blank" rel="noopener">Terms</a>
                    <a href="{{ $websiteUrl }}/privacy-policy.html" target="_blank" rel="noopener">Privacy</a>
                    <a href="{{ $websiteUrl }}/contact.html" target="_blank" rel="noopener">Support</a>
                </nav>
            </footer>
        </div>
    </div>
    @include('partials.confirm-modal')
</body>
</html>

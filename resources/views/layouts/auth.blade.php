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
    <main class="auth-shell">
        <aside class="auth-brand d-none d-lg-flex">
            <a href="{{ config('bookkeeper.website_url') }}">
                <img src="{{ asset('images/logo.png') }}" alt="BookKeepingLLC" class="auth-logo">
            </a>

            <div>
                <h2>Your books, built from the statements you already have.</h2>
                <ul class="auth-points">
                    <li><i class="bi bi-cloud-arrow-up"></i><span>Drop your bank or card statements as PDF or Excel.</span></li>
                    <li><i class="bi bi-journal-text"></i><span>Get a General Ledger and Balance Sheet for any dates you choose.</span></li>
                    <li><i class="bi bi-plus-circle"></i><span>One simple plan. Add more reports only when you need them.</span></li>
                </ul>
            </div>

            <small>&copy; {{ date('Y') }} BookKeepingLLC</small>
        </aside>

        <section class="auth-main">
            <div class="auth-theme">@include('partials.theme-toggle')</div>
            <div @class(['auth-card', 'auth-card-wide' => $wide ?? false])>
                <a href="{{ config('bookkeeper.website_url') }}" class="d-inline-block d-lg-none mb-4">
                    <img src="{{ asset('images/logo.png') }}" alt="BookKeepingLLC" class="auth-logo">
                </a>

                @yield('content')
            </div>
        </section>
    </main>
    @include('partials.confirm-modal')
</body>
</html>

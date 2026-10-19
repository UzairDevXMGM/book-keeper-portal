@extends('layouts.app')

@section('title', 'Admin guide')

@php
    $sections = [
        'overview' => ['Overview', 'grid-1x2'],
        'customers' => ['Customers', 'people'],
        'plans' => ['Plans & prices', 'box-seam'],
        'catalog' => ['Report catalog', 'journal-richtext'],
        'categories' => ['Categories', 'tags'],
        'users' => ['Users', 'person-gear'],
        'audit' => ['Audit log', 'clock-history'],
        'settings' => ['Settings', 'gear'],
        'customer-side' => ['What customers see', 'window'],
        'setup' => ['Server & Stripe', 'shield-check'],
    ];
@endphp

@section('content')
    <div class="page-head">
        <div>
            <h1 class="h3">Admin guide</h1>
            <div class="small text-secondary">How to run the platform: customers, prices, reports, categories and settings.</div>
        </div>
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
            <section id="overview" class="card mb-4">
                <div class="card-body p-4">
                    <h2 class="h5 mb-2"><i class="bi bi-grid-1x2 me-2"></i>Overview</h2>
                    <p class="small mb-0">How many customers there are, how many pay, the <b>monthly recurring revenue</b> (each paying business's plan plus its active add-ons) and who signed up but has not paid. The list shows the newest businesses; click one to open it.</p>
                </div>
            </section>

            <section id="customers" class="card mb-4">
                <div class="card-body p-4">
                    <h2 class="h5 mb-2"><i class="bi bi-people me-2"></i>Customers</h2>
                    <ul class="small mb-0">
                        <li>Search by business or email. Open a business to see its plan, which reports it can open, its add-ons and recent activity.</li>
                        <li><b>Block / allow login</b>: stops the owner from signing in. Their books are kept.</li>
                        <li><b>Give an add-on for free</b> (for example as a goodwill gesture) or <b>remove</b> one. Free add-ons are not charged in Stripe.</li>
                        <li>You cannot open a customer's books or statements: they are private to the business.</li>
                    </ul>
                </div>
            </section>

            <section id="plans" class="card mb-4">
                <div class="card-body p-4">
                    <h2 class="h5 mb-2"><i class="bi bi-box-seam me-2"></i>Plans &amp; prices</h2>
                    <p class="small">
                        @foreach ($plans as $plan)
                            <b>{{ $plan->name }}</b>: {{ money($plan->price) }}/{{ $plan->billing_interval }}{{ $loop->last ? '.' : ', ' }}
                        @endforeach
                    </p>
                    <ul class="small mb-0">
                        <li><b>Edit</b> a plan to change its name, description, <b>price</b> and the <b>reports included</b> in it.</li>
                        <li>A new price goes on sale in Stripe <b>as soon as you save</b>. Existing subscribers keep the price they signed up with.</li>
                        <li><b>Sync with Stripe</b> is only needed if a save could not reach Stripe (the message says so), or after changing Stripe keys.</li>
                    </ul>
                </div>
            </section>

            <section id="catalog" class="card mb-4">
                <div class="card-body p-4">
                    <h2 class="h5 mb-2"><i class="bi bi-journal-richtext me-2"></i>Report catalog</h2>
                    <p class="small mb-0">All {{ $reportCount }} reports. Those in a plan show “Included”; the other {{ $addonCount }} are monthly add-ons at the price shown. <b>Edit</b> a report to change its name, the <b>description</b> customers read, its category, its <b>add-on price</b> (live in Stripe on save) or to stop offering it.</p>
                </div>
            </section>

            <section id="categories" class="card mb-4">
                <div class="card-body p-4">
                    <h2 class="h5 mb-2"><i class="bi bi-tags me-2"></i>Categories</h2>
                    <p class="small">The {{ $categoryCount }} categories (Fuel, Meals &amp; Entertainment, Rent…) that uploaded transactions are sorted into, for every customer.</p>
                    <ul class="small mb-0">
                        <li><b>Keywords</b>: merchant names that belong to a category (“SHELL” → Fuel). Adding a keyword moves it away from any other category.</li>
                        <li><b>Posts to</b>: the account a category uses. <b>Industry accounts</b> send it to an industry's own account (Trucking: Fuel → 5215 Fuel - Trucks).</li>
                        <li><b>Add category</b> creates a new one; switching one off stops using it.</li>
                        <li>Changes apply to statements uploaded from now on. Customers' own “Always” rules always win over these.</li>
                    </ul>
                </div>
            </section>

            <section id="users" class="card mb-4">
                <div class="card-body p-4">
                    <h2 class="h5 mb-2"><i class="bi bi-person-gear me-2"></i>Users</h2>
                    <p class="small mb-0">Everyone who can log in. <b>Disable</b> signs a person out and blocks them; <b>Send reset link</b> emails a password reset. Admins are created from the server settings (ADMIN_EMAIL / ADMIN_PASSWORD).</p>
                </div>
            </section>

            <section id="audit" class="card mb-4">
                <div class="card-body p-4">
                    <h2 class="h5 mb-2"><i class="bi bi-clock-history me-2"></i>Audit log</h2>
                    <p class="small mb-0">Sign-ins, payments, uploads, postings and admin changes, newest first, with who did it and from where. Use it to answer “who changed this, and when?”.</p>
                </div>
            </section>

            <section id="settings" class="card mb-4">
                <div class="card-body p-4">
                    <h2 class="h5 mb-2"><i class="bi bi-gear me-2"></i>Settings</h2>
                    <ul class="small mb-0">
                        <li><b>Review threshold</b> (now {{ $threshold }}%): uploaded lines we are less sure about than this wait on Check categories instead of going straight into the books. Higher = more checking, fewer mistakes.</li>
                        <li><b>Largest statement upload</b> (now {{ $maxMb }} MB).</li>
                        <li><b>New sign-ups</b> (now {{ $signupsOpen ? 'open' : 'closed' }}): close it to stop new registrations; existing customers are not affected.</li>
                    </ul>
                </div>
            </section>

            <section id="customer-side" class="card mb-4">
                <div class="card-body p-4">
                    <h2 class="h5 mb-2"><i class="bi bi-window me-2"></i>What customers see</h2>
                    <p class="small mb-0">A customer registers, pays for the plan with Stripe and lands on a Dashboard with a <b>Get started</b> checklist: dump a statement, check categories, open the reports. Their own <b>Guide</b> (in their menu) explains every page. Add-on reports and the dashboard cards that belong to them stay locked until bought.</p>
                </div>
            </section>

            <section id="setup" class="card mb-4">
                <div class="card-body p-4">
                    <h2 class="h5 mb-2"><i class="bi bi-shield-check me-2"></i>Server &amp; Stripe</h2>
                    <ul class="small mb-0">
                        <li>Stripe is in <b>{{ $stripeMode }} mode</b>. {{ $stripeMode === 'test' ? 'No real money is taken. Switch to live keys in the server settings (.env) when ready.' : 'Real payments are taken.' }}</li>
                        <li>Stripe webhook secret: <b>{{ $webhookSet ? 'set' : 'not set' }}</b>. {{ $webhookSet ? '' : 'Without it, renewals, cancellations and invoice emails from Stripe are not received. Add the webhook in Stripe (endpoint /stripe/webhook) and put its secret in STRIPE_WEBHOOK_SECRET.' }}</li>
                        <li>Keys, database and mail server live in the server's .env file and are never shown in the admin pages.</li>
                    </ul>
                </div>
            </section>
        </div>
    </div>
@endsection

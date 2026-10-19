<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AddonController;
use App\Http\Controllers\Admin;
use App\Http\Controllers\Admin\OverviewController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\JournalEntryController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReconcileController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\RuleController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

// Login, register, forgot / reset password and logout routes come from Fortify (config/fortify.php).
// The Stripe webhook (POST /stripe/webhook) comes from Cashier.

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'));

Route::middleware('auth')->group(function () {
    Route::prefix('billing')->name('billing.')->controller(BillingController::class)->group(function () {
        Route::get('/', 'show')->name('show');
        Route::get('/checkout', 'checkout')->name('checkout');
        Route::get('/success', 'success')->name('success');
        Route::get('/portal', 'portal')->name('portal');
    });

    Route::middleware('subscribed')->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');

        // My profile (name, email and password are saved by Fortify's /user/* routes)
        Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
        Route::post('/profile/photo', [ProfileController::class, 'updatePhoto'])->name('profile.photo.update');
        Route::delete('/profile/photo', [ProfileController::class, 'destroyPhoto'])->name('profile.photo.destroy');
        Route::get('/profile/photo/{user}', [ProfileController::class, 'photo'])->name('profile.photo');

        // Customer workspace
        Route::middleware('customer')->group(function () {
            Route::patch('/profile/business', [ProfileController::class, 'updateBusiness'])->name('profile.business');

            Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
            Route::get('/reports/{report:code}', [ReportController::class, 'show'])->name('reports.show');
            Route::post('/reports/{report:code}/add', [AddonController::class, 'store'])->name('addons.store');
            Route::delete('/addons/{addon}', [AddonController::class, 'destroy'])->name('addons.destroy');

            Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions.index');

            Route::get('/accounts', [AccountController::class, 'index'])->name('accounts.index');
            Route::post('/accounts', [AccountController::class, 'store'])->name('accounts.store');
            Route::patch('/accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');

            Route::post('/transactions/lines/{line}/category', [TransactionController::class, 'recategorize'])->name('transactions.recategorize');
            Route::get('/guide', [HelpController::class, 'show'])->name('help');
            Route::redirect('/help', '/guide');
            Route::get('/rules', [RuleController::class, 'index'])->name('rules.index');
            Route::patch('/rules/{rule}', [RuleController::class, 'update'])->name('rules.update');
            Route::delete('/rules/{rule}', [RuleController::class, 'destroy'])->name('rules.destroy');
            Route::get('/journal/create', [JournalEntryController::class, 'create'])->name('journal.create');
            Route::post('/journal', [JournalEntryController::class, 'store'])->name('journal.store');
            Route::get('/journal/{entry}', [JournalEntryController::class, 'show'])->name('journal.show');
            Route::post('/journal/{entry}/reverse', [JournalEntryController::class, 'reverse'])->name('journal.reverse');

            Route::get('/alerts', AlertController::class)->name('alerts.index');

            // Dump your data
            Route::get('/upload', [UploadController::class, 'index'])->name('uploads.index');
            Route::post('/upload', [UploadController::class, 'store'])->name('uploads.store');
            Route::get('/upload/{upload}', [UploadController::class, 'show'])->name('uploads.show');
            Route::post('/upload/{upload}/post', [UploadController::class, 'post'])->name('uploads.post');
            Route::get('/upload/{upload}/download', [UploadController::class, 'download'])->name('uploads.download');
            Route::delete('/upload/{upload}', [UploadController::class, 'destroy'])->name('uploads.destroy');

            // Review queue
            Route::get('/review', [ReviewController::class, 'index'])->name('review.index');
            Route::post('/review/bulk', [ReviewController::class, 'bulk'])->name('review.bulk');
            Route::post('/review/{row}/approve', [ReviewController::class, 'approve'])->name('review.approve');
            Route::post('/review/suspense/{line}', [ReviewController::class, 'reclassify'])->name('review.reclassify');

            // Bank / card reconciliation
            Route::get('/reconcile', [ReconcileController::class, 'index'])->name('reconcile.index');
            Route::post('/reconcile', [ReconcileController::class, 'store'])->name('reconcile.store');
            Route::get('/reconcile/{reconciliation}', [ReconcileController::class, 'show'])->name('reconcile.show');
            Route::patch('/reconcile/{reconciliation}', [ReconcileController::class, 'update'])->name('reconcile.update');
            Route::delete('/reconcile/{reconciliation}', [ReconcileController::class, 'destroy'])->name('reconcile.destroy');
        });

        // Platform admin
        Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
            Route::get('/', OverviewController::class)->name('overview');

            Route::get('/customers', [Admin\CustomerController::class, 'index'])->name('customers.index');
            Route::get('/customers/{company}', [Admin\CustomerController::class, 'show'])->name('customers.show');
            Route::post('/customers/{company}/access', [Admin\CustomerController::class, 'toggleAccess'])->name('customers.access');
            Route::post('/customers/{company}/addons', [Admin\CustomerController::class, 'grantAddon'])->name('customers.grant');
            Route::delete('/customers/{company}/addons/{addon}', [Admin\CustomerController::class, 'revokeAddon'])->name('customers.revoke');

            Route::get('/plans', [Admin\PlanController::class, 'index'])->name('plans.index');
            Route::post('/plans/sync-stripe', [Admin\PlanController::class, 'syncStripe'])->name('plans.sync');
            Route::get('/plans/{plan}/edit', [Admin\PlanController::class, 'edit'])->name('plans.edit');
            Route::patch('/plans/{plan}', [Admin\PlanController::class, 'update'])->name('plans.update');

            Route::get('/categories', [Admin\CategoryTypeController::class, 'index'])->name('categories.index');
            Route::post('/categories', [Admin\CategoryTypeController::class, 'store'])->name('categories.store');
            Route::get('/categories/{category}/edit', [Admin\CategoryTypeController::class, 'edit'])->name('categories.edit');
            Route::patch('/categories/{category}', [Admin\CategoryTypeController::class, 'update'])->name('categories.update');
            Route::post('/categories/{category}/keywords', [Admin\CategoryTypeController::class, 'addKeyword'])->name('categories.keywords.store');
            Route::delete('/categories/{category}/keywords/{rule}', [Admin\CategoryTypeController::class, 'removeKeyword'])->name('categories.keywords.destroy');
            Route::post('/categories/{category}/industries', [Admin\CategoryTypeController::class, 'setIndustryAccount'])->name('categories.industries.store');
            Route::delete('/categories/{category}/industries/{industryAccount}', [Admin\CategoryTypeController::class, 'removeIndustryAccount'])->name('categories.industries.destroy');

            Route::get('/reports', [Admin\ReportCatalogController::class, 'index'])->name('reports.index');
            Route::get('/reports/{report}/edit', [Admin\ReportCatalogController::class, 'edit'])->name('reports.edit');
            Route::patch('/reports/{report}', [Admin\ReportCatalogController::class, 'update'])->name('reports.update');

            Route::get('/users', [Admin\UserController::class, 'index'])->name('users.index');
            Route::post('/users/{user}/toggle', [Admin\UserController::class, 'toggle'])->name('users.toggle');
            Route::post('/users/{user}/reset', [Admin\UserController::class, 'sendReset'])->name('users.reset');

            Route::get('/audit', [Admin\AuditLogController::class, 'index'])->name('audit.index');
            Route::get('/guide', [HelpController::class, 'admin'])->name('guide');

            Route::get('/settings', [Admin\SettingsController::class, 'show'])->name('settings.show');
            Route::patch('/settings', [Admin\SettingsController::class, 'update'])->name('settings.update');
        });
    });
});

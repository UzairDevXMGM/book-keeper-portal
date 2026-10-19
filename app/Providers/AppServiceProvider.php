<?php

namespace App\Providers;

use App\Models\Company;
use App\View\Composers\AppLayoutComposer;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Cashier\Cashier;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The company is the Stripe customer, not the user
        Cashier::useCustomerModel(Company::class);

        Password::defaults(fn () => Password::min(10)->letters()->numbers());

        Paginator::useBootstrapFive();

        View::composer('layouts.app', AppLayoutComposer::class);
    }
}

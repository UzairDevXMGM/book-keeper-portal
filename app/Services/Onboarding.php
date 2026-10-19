<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\ImportRow;
use App\Models\User;

/**
 * "Get started" checklist on the dashboard for a new business. Every step is worked out from what the business has
 * actually done, so it ticks itself off; the card disappears once all steps are done.
 */
class Onboarding
{
    /** @return list<array{key: string, title: string, text: string, done: bool, url: string, button: string}> */
    public static function steps(Company $company, User $user): array
    {
        $uploaded = $company->uploads()->exists();
        $waiting = ImportRow::where('company_id', $company->id)->where('status', ImportRow::STATUS_NEEDS_REVIEW)->count();
        $suspenseIds = $company->accounts()->whereIn('system_key', ['suspense_expense', 'suspense_income'])->pluck('id');
        // Money still sitting in "Uncategorized" (recategorizing moves it out, so the balance goes back to zero)
        $suspense = $company->journalLines()->whereIn('account_id', $suspenseIds)->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) AS net')->value('net');
        $uncategorized = abs((float) $suspense) >= 0.005;

        return [
            [
                'key' => 'account',
                'title' => 'Create your account',
                'text' => 'Your business and plan are set up.',
                'done' => true,
                'url' => route('profile.show'),
                'button' => 'My profile',
            ],
            [
                'key' => 'upload',
                'title' => 'Dump your first statement',
                'text' => 'Upload a bank or credit card statement (PDF, Excel or CSV). We read every transaction and give it a category.',
                'done' => $uploaded,
                'url' => route('uploads.index'),
                'button' => 'Dump your data',
            ],
            [
                'key' => 'review',
                'title' => 'Check the categories',
                'text' => $waiting
                    ? "{$waiting} transaction(s) need you to confirm their category. It takes a few clicks."
                    : 'When we are not sure about a transaction, it waits here for you to pick its category.',
                'done' => $uploaded && $waiting === 0 && ! $uncategorized,
                'url' => route('review.index'),
                'button' => 'Check categories',
            ],
            [
                'key' => 'reports',
                'title' => 'See your reports',
                'text' => 'Open your Profit & Loss to see what you earned and spent, and the Balance Sheet for what you own and owe.',
                'done' => AuditLog::where('user_id', $user->id)->where('action', 'report.first_view')->exists(),
                'url' => route('reports.show', 'ACC-26'),
                'button' => 'Open Profit & Loss',
            ],
        ];
    }
}

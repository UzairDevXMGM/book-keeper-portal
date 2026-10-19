<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Writes the audit trail shown on Admin → Audit log. Never lets a logging failure break the action being logged.
 *
 * Audit::log('journal.reversed', $entry, ['reversal' => 42]);
 */
class Audit
{
    public static function log(string $action, ?Model $subject = null, array $details = [], ?Company $company = null, ?User $user = null): void
    {
        try {
            $user ??= auth()->user();
            $company ??= match (true) {
                $subject instanceof Company => $subject,
                $subject !== null && isset($subject->company_id) => Company::find($subject->company_id),
                default => $user?->company,
            };

            AuditLog::create([
                'user_id' => $user?->id,
                'company_id' => $company?->id,
                'action' => $action,
                'subject_type' => $subject ? $subject->getMorphClass() : null,
                'subject_id' => $subject?->getKey(),
                'details' => $details ?: null,
                'ip_address' => request()?->ip(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}

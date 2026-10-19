<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Cashier\Billable;

/**
 * The business whose books we keep. It is also the Stripe customer: the plan and add-ons are billed to the company,
 * not to the person who signed up (Cashier::useCustomerModel in AppServiceProvider).
 */
class Company extends Model
{
    use Billable, SoftDeletes;

    /** Cashier subscription type of the main plan. */
    public const SUBSCRIPTION = 'default';

    protected $fillable = [
        'user_id',
        'name',
        'legal_name',
        'business_type',
        'industry',
        'city',
        'state',
        'phone',
        'country',
        'currency',
        'fiscal_year_start_month',
        'accounting_method',
        'books_start_date',
        'locked_through',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_year_start_month' => 'integer',
            'books_start_date' => DateOnly::class,
            'locked_through' => DateOnly::class,
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function uploads(): HasMany
    {
        return $this->hasMany(Upload::class);
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function categorizationRules(): HasMany
    {
        return $this->hasMany(CategorizationRule::class);
    }

    public function addons(): HasMany
    {
        return $this->hasMany(CompanyAddon::class);
    }

    /** Account with a special role (suspense_expense, opening_balance_equity, ...). */
    public function systemAccount(string $key): ?Account
    {
        return $this->accounts()->where('system_key', $key)->first();
    }

    public function hasActivePlan(): bool
    {
        return $this->subscribed(self::SUBSCRIPTION);
    }

    public function stripeName(): ?string
    {
        return $this->name;
    }

    /** Stripe receipts go to the owner's email (companies have no email column). */
    public function stripeEmail(): ?string
    {
        return $this->owner?->email;
    }
}

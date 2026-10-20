<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyAddon extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'company_id',
        'report_id',
        'price',
        'status',
        'starts_at',
        'ends_at',
        'cancelled_at',
        'stripe_subscription_item_id',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** Add-ons whose report is open right now (a removed one stays open until the end of the month already paid). */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE)->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    /** Add-ons that will be charged again next month (not removed). */
    public function scopeRenewing(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE)->whereNull('ends_at');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }
}

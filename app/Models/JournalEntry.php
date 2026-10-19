<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Double-entry journal header. Never edited or deleted once saved; corrections are reversing entries. */
class JournalEntry extends Model
{
    protected $fillable = [
        'company_id',
        'entry_number',
        'entry_date',
        'description',
        'reference',
        'source',
        'upload_id',
        'reverses_entry_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'entry_number' => 'integer',
            'entry_date' => DateOnly::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('line_no');
    }

    public function upload(): BelongsTo
    {
        return $this->belongsTo(Upload::class);
    }

    /** The statement line this entry was posted from, when it came from an upload. */
    public function importRow(): HasOne
    {
        return $this->hasOne(ImportRow::class);
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

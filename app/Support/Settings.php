<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Platform settings stored in the `settings` table, with their defaults and rules. Cached until changed. */
class Settings
{
    public const DEFINITIONS = [
        'review_threshold' => [
            'label' => 'Review threshold (confidence %)',
            'help' => 'Uploaded lines whose suggested category is less certain than this wait in the review queue.',
            'default' => 70,
            'rules' => ['required', 'integer', 'between:50,99'],
        ],
        'upload_max_mb' => [
            'label' => 'Largest statement upload (MB)',
            'help' => 'Per file. PDF, Excel (.xlsx) and CSV.',
            'default' => 10,
            'rules' => ['required', 'integer', 'between:1,20'],
        ],
        'registration_open' => [
            'label' => 'New sign-ups',
            'help' => 'When off, the register page tells visitors that sign-ups are paused. Existing customers can still log in.',
            'default' => true,
            'rules' => ['required', 'boolean'],
        ],
    ];

    private const CACHE_KEY = 'platform-settings';

    public static function get(string $key): mixed
    {
        $stored = Cache::rememberForever(self::CACHE_KEY, fn () => DB::table('settings')->pluck('value', 'key')->all());
        $default = self::DEFINITIONS[$key]['default'];

        if (! array_key_exists($key, $stored) || $stored[$key] === null) {
            return $default;
        }

        return match (gettype($default)) {
            'integer' => (int) $stored[$key],
            'boolean' => (bool) $stored[$key],
            default => $stored[$key],
        };
    }

    /** @param array<string, mixed> $values */
    public static function set(array $values): void
    {
        foreach ($values as $key => $value) {
            if (array_key_exists($key, self::DEFINITIONS)) {
                DB::table('settings')->updateOrInsert(['key' => $key], ['value' => is_bool($value) ? (int) $value : $value, 'updated_at' => now(), 'created_at' => now()]);
            }
        }
        Cache::forget(self::CACHE_KEY);
    }

    public static function all(): array
    {
        return collect(self::DEFINITIONS)->mapWithKeys(fn ($definition, $key) => [$key => self::get($key)])->all();
    }
}

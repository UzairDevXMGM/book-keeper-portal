<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Admin → Settings: platform switches that do not need a deploy. */
class SettingsController extends Controller
{
    public function show(): View
    {
        return view('admin.settings', [
            'definitions' => Settings::DEFINITIONS,
            'values' => Settings::all(),
            'stripeMode' => str_starts_with((string) config('cashier.secret'), 'sk_live_') ? 'live' : (config('cashier.secret') ? 'test' : 'not set'),
            'mailer' => config('mail.default'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->merge(['registration_open' => $request->boolean('registration_open')]);
        $data = $request->validate(collect(Settings::DEFINITIONS)->map(fn ($d) => $d['rules'])->all());

        $before = Settings::all();
        Settings::set($data);
        Audit::log('admin.settings_updated', null, ['changed' => array_keys(array_diff_assoc(array_map('strval', $data), array_map('strval', $before)))]);

        return back()->with('status', 'Settings saved.');
    }
}

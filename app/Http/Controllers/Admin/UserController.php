<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

/** Admin → Users: everyone who can log in, with disable / enable and a password reset email. */
class UserController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));
        $role = in_array($request->query('role'), [User::ROLE_ADMIN, User::ROLE_CUSTOMER], true) ? $request->query('role') : null;

        return view('admin.users.index', [
            // company is a "oldest of many" relation (a join), so its columns need the table name
            'users' => User::with('company:companies.id,companies.user_id,companies.name')
                ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
                ->when($role, fn ($q) => $q->where('role', $role))
                ->latest()->latest('id')->paginate(20)->withQueryString(),
            'search' => $search,
            'role' => $role,
        ]);
    }

    public function toggle(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->with('error', 'You cannot disable your own account.');
        }

        $user->forceFill(['is_active' => ! $user->is_active])->save();
        if (! $user->is_active) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }
        Audit::log($user->is_active ? 'admin.user_enabled' : 'admin.user_disabled', $user, ['email' => $user->email]);

        return back()->with('status', "{$user->email} ".($user->is_active ? 'can log in again.' : 'can no longer log in.'));
    }

    public function sendReset(User $user): RedirectResponse
    {
        $status = Password::sendResetLink(['email' => $user->email]);
        Audit::log('admin.password_reset_sent', $user, ['email' => $user->email]);

        return back()->with($status === Password::RESET_LINK_SENT ? 'status' : 'error',
            $status === Password::RESET_LINK_SENT ? "Password reset link sent to {$user->email}." : 'The reset email could not be sent right now ('.__($status).').');
    }
}

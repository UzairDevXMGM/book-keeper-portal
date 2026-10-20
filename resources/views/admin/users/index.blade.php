@extends('layouts.app')

@section('title', 'Users')

@section('content')
    <div class="page-head">
        <div>
            <h1 class="h3">Users</h1>
            <div class="small text-secondary">Everyone who can log in. Disabling a login signs that person out immediately; their books are kept.</div>
        </div>
    </div>

    <form method="GET" class="card card-body report-toolbar mb-3 d-flex flex-row flex-wrap align-items-end gap-2">
        <div>
            <label for="q" class="form-label small mb-1">Search</label>
            <input id="q" type="search" name="q" value="{{ $search }}" placeholder="Name or email" class="form-control form-control-sm">
        </div>
        <div>
            <label for="role" class="form-label small mb-1">Role</label>
            <select id="role" name="role" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="customer" @selected($role === 'customer')>Business owners</option>
                <option value="admin" @selected($role === 'admin')>Administrators</option>
            </select>
        </div>
        <button type="submit" class="btn btn-sm btn-primary">Filter</button>
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table report-table table-hover align-middle mb-0">
                <thead><tr><th>User</th><th>Role</th><th>Business</th><th>Last login</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($users as $user)
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <x-avatar :user="$user" />
                                    <div class="lh-sm"><div class="fw-semibold">{{ $user->name }}</div><div class="small text-secondary">{{ $user->email }}</div></div>
                                </div>
                            </td>
                            <td><span class="badge {{ $user->isAdmin() ? 'text-bg-dark' : 'text-bg-light border' }}">{{ $user->isAdmin() ? 'Administrator' : 'Business owner' }}</span></td>
                            <td class="small">
                                @if ($user->company)
                                    <a href="{{ route('admin.customers.show', $user->company) }}">{{ $user->company->name }}</a>
                                @else — @endif
                            </td>
                            <td class="small text-nowrap">{{ $user->last_login_at?->diffForHumans() ?? 'Never' }}</td>
                            <td><span class="badge {{ $user->is_active ? 'text-bg-primary' : 'text-bg-secondary' }}">{{ $user->is_active ? 'Active' : 'Disabled' }}</span></td>
                            <td class="text-end text-nowrap">
                                <form method="POST" action="{{ route('admin.users.reset', $user) }}" class="d-inline" data-confirm="We email {{ $user->email }} a link to choose a new password." data-confirm-title="Send a password reset link?" data-confirm-button="Send link">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-link">Send reset link</button>
                                </form>
                                @unless ($user->is(auth()->user()))
                                    <form method="POST" action="{{ route('admin.users.toggle', $user) }}" class="d-inline"
                                          data-confirm="{{ $user->is_active ? $user->email.' is signed out and cannot log in until enabled again.' : $user->email.' can log in again.' }}" data-confirm-title="{{ $user->is_active ? 'Disable this login?' : 'Enable this login?' }}" data-confirm-button="{{ $user->is_active ? 'Disable' : 'Enable' }}" @if ($user->is_active) data-confirm-tone="danger" @endif>
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-link {{ $user->is_active ? 'text-danger' : '' }}">{{ $user->is_active ? 'Disable' : 'Enable' }}</button>
                                    </form>
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-secondary py-4">No users match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @include('partials.pagination', ['paginator' => $users, 'label' => 'users'])
@endsection

@extends('layouts.auth')

@section('title', 'Choose a new password')

@section('content')
    <h1 class="h3 mb-1">Choose a new password</h1>
    <p class="text-secondary small mb-3">You'll use it the next time you log in.</p>

    <form method="POST" action="{{ route('password.update') }}" novalidate>
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <div class="form-floating mb-2">
            <input id="email" type="email" name="email" value="{{ old('email', $request->email) }}" placeholder="Email"
                   class="form-control @error('email') is-invalid @enderror" required autocomplete="username">
            <label for="email">Email</label>
            @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <div class="form-floating password-field mb-2">
            <input id="password" type="password" name="password" placeholder="New password"
                   class="form-control @error('password') is-invalid @enderror"
                   required autofocus autocomplete="new-password" aria-describedby="password-help">
            <label for="password">New password</label>
            <button type="button" class="password-toggle" data-password-toggle="#password" aria-label="Show password" aria-pressed="false">
                <i class="bi bi-eye"></i>
            </button>
            @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <div class="form-floating mb-1">
            <input id="password_confirmation" type="password" name="password_confirmation" placeholder="Confirm new password"
                   class="form-control" required autocomplete="new-password">
            <label for="password_confirmation">Confirm new password</label>
        </div>
        <div id="password-help" class="form-text mt-0 mb-3">At least 10 characters, with letters and numbers.</div>

        <button type="submit" class="btn btn-primary w-100">
            <span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true"></span>
            Save new password
        </button>
    </form>
@endsection

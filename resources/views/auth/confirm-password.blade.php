@extends('layouts.auth')

@section('title', 'Confirm password')

@section('content')
    <h1 class="h3 mb-1">Confirm your password</h1>
    <p class="text-secondary small mb-3">This is a secure area. Please enter your password again to continue.</p>

    <form method="POST" action="{{ route('password.confirm.store') }}" novalidate>
        @csrf

        <div class="form-floating password-field mb-3">
            <input id="password" type="password" name="password" placeholder="Password"
                   class="form-control @error('password') is-invalid @enderror" required autofocus autocomplete="current-password">
            <label for="password">Password</label>
            <button type="button" class="password-toggle" data-password-toggle="#password" aria-label="Show password" aria-pressed="false">
                <i class="bi bi-eye"></i>
            </button>
            @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <button type="submit" class="btn btn-primary w-100">
            <span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true"></span>
            Confirm
        </button>
    </form>
@endsection

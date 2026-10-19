@extends('layouts.auth')

@section('title', 'Log in')

@section('content')
    <h1 class="h3 mb-1">Welcome back</h1>
    <p class="text-secondary small mb-3">Log in to your dashboard.</p>

    @if (session('status'))
        <div class="alert alert-success small">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('login') }}" novalidate>
        @csrf

        <div class="form-floating mb-2">
            <input id="email" type="email" name="email" value="{{ old('email') }}" placeholder="Email"
                   class="form-control @error('email') is-invalid @enderror" required autofocus autocomplete="username">
            <label for="email">Email</label>
            @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <div class="form-floating password-field mb-2">
            <input id="password" type="password" name="password" placeholder="Password"
                   class="form-control @error('password') is-invalid @enderror" required autocomplete="current-password">
            <label for="password">Password</label>
            <button type="button" class="password-toggle" data-password-toggle="#password" aria-label="Show password" aria-pressed="false">
                <i class="bi bi-eye"></i>
            </button>
            @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <div class="d-flex justify-content-between align-items-center small mb-3">
            <div class="form-check mb-0">
                <input class="form-check-input" type="checkbox" name="remember" id="remember" @checked(old('remember'))>
                <label class="form-check-label" for="remember">Keep me logged in</label>
            </div>
            <a href="{{ route('password.request') }}">Forgot password?</a>
        </div>

        <button type="submit" class="btn btn-primary w-100">
            <span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true"></span>
            Log in
        </button>
    </form>

    <p class="text-center text-secondary small mt-3 mb-0">
        New here? <a href="{{ route('register') }}">Create an account</a>
    </p>
@endsection

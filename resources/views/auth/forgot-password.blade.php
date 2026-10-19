@extends('layouts.auth')

@section('title', 'Forgot password')

@section('content')
    <h1 class="h3 mb-1">Forgot your password?</h1>
    <p class="text-secondary small mb-3">Enter the email you signed up with and we'll send you a link to choose a new one.</p>

    @if (session('status'))
        <div class="alert alert-success small">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" novalidate>
        @csrf

        <div class="form-floating mb-3">
            <input id="email" type="email" name="email" value="{{ old('email') }}" placeholder="Email"
                   class="form-control @error('email') is-invalid @enderror" required autofocus autocomplete="username">
            <label for="email">Email</label>
            @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <button type="submit" class="btn btn-primary w-100">
            <span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true"></span>
            Email me a reset link
        </button>
    </form>

    <p class="text-center small mt-3 mb-0">
        <a href="{{ route('login') }}"><i class="bi bi-arrow-left me-1"></i>Back to log in</a>
    </p>
@endsection

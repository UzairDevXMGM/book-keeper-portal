@extends('layouts.auth')

@section('title', 'Sign-ups paused')

@section('content')
    <i class="bi bi-pause-circle display-6 text-secondary"></i>
    <h1 class="h3 mt-3 mb-1">Sign-ups are paused</h1>
    <p class="text-secondary">We are not taking new accounts right now. Please check back soon or contact us through the website.</p>
    <div class="d-flex gap-2">
        <a href="{{ route('login') }}" class="btn btn-primary">Log in</a>
        <a href="{{ config('bookkeeper.website_url') }}/contact.html" class="btn btn-outline-secondary">Contact us</a>
    </div>
@endsection

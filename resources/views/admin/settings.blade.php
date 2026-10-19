@extends('layouts.app')

@section('title', 'Settings')

@section('content')
    <div class="page-head">
        <div>
            <h1 class="h3">Settings</h1>
            <div class="small text-secondary">Platform switches. Changes apply immediately to every customer.</div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-xl-8">
            <form method="POST" action="{{ route('admin.settings.update') }}" class="card" novalidate>
                @csrf
                @method('PATCH')
                <div class="card-body p-4">
                    @foreach ($definitions as $key => $definition)
                        <div class="row g-2 align-items-center py-3 border-bottom">
                            <div class="col-md-7">
                                <label for="{{ $key }}" class="fw-semibold d-block">{{ $definition['label'] }}</label>
                                <div class="small text-secondary">{{ $definition['help'] }}</div>
                            </div>
                            <div class="col-md-5">
                                @if (is_bool($definition['default']))
                                    <div class="form-check form-switch">
                                        <input type="hidden" name="{{ $key }}" value="0">
                                        <input class="form-check-input" type="checkbox" role="switch" id="{{ $key }}" name="{{ $key }}" value="1" @checked(old($key, $values[$key]))>
                                        <label class="form-check-label small" for="{{ $key }}">{{ old($key, $values[$key]) ? 'On' : 'Off' }}</label>
                                    </div>
                                @else
                                    <input id="{{ $key }}" type="number" name="{{ $key }}" value="{{ old($key, $values[$key]) }}" class="form-control @error($key) is-invalid @enderror" required>
                                    @error($key) <div class="invalid-feedback">{{ $message }}</div> @enderror
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="card-footer bg-white d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary">Save settings</button>
                </div>
            </form>
        </div>

        <div class="col-xl-4">
            <div class="card">
                <div class="card-body p-4">
                    <h2 class="card-title-sm mb-3">Connections</h2>
                    <ul class="list-unstyled small mb-0">
                        <li class="d-flex justify-content-between py-2 border-bottom">
                            <span><i class="bi bi-stripe me-2"></i>Stripe</span>
                            <span @class(['badge', 'text-bg-primary' => $stripeMode === 'live', 'text-bg-warning' => $stripeMode === 'test', 'text-bg-danger' => $stripeMode === 'not set'])>{{ ucfirst($stripeMode) }} mode</span>
                        </li>
                        <li class="d-flex justify-content-between py-2">
                            <span><i class="bi bi-envelope me-2"></i>Email</span>
                            <span class="badge text-bg-light border">{{ strtoupper($mailer) }}</span>
                        </li>
                    </ul>
                    <p class="small text-secondary mt-2 mb-0">Keys and mail server are set in the <code>.env</code> file on the server.</p>
                </div>
            </div>
        </div>
    </div>
@endsection

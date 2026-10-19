@extends('layouts.app')

@section('title', 'Edit '.$plan->name)

@php $included = old('reports', $plan->reports->pluck('id')->all()); @endphp

@section('content')
    <a href="{{ route('admin.plans.index') }}" class="small"><i class="bi bi-arrow-left me-1"></i>Plans</a>
    <div class="page-head mt-1"><h1 class="h3">Edit {{ $plan->name }} plan</h1></div>

    <form method="POST" action="{{ route('admin.plans.update', $plan) }}" class="card" novalidate>
        @csrf
        @method('PATCH')
        <div class="card-body p-4">
            <div class="row g-2 mb-3">
                <div class="col-md-5">
                    <div class="form-floating">
                        <input id="name" name="name" value="{{ old('name', $plan->name) }}" placeholder="Name" class="form-control @error('name') is-invalid @enderror" required>
                        <label for="name">Plan name</label>
                        @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-floating">
                        <input id="price" name="price" value="{{ old('price', $plan->price) }}" inputmode="decimal" placeholder="0.00" class="form-control @error('price') is-invalid @enderror" required>
                        <label for="price">Price per {{ $plan->billing_interval }} ($)</label>
                        @error('price') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
                <div class="col-md-4 d-flex align-items-center">
                    <div class="form-check form-switch ms-md-2">
                        <input type="hidden" name="is_active" value="0">
                        <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" @checked(old('is_active', $plan->is_active))>
                        <label class="form-check-label" for="is_active">Offered to new customers</label>
                    </div>
                </div>
                <div class="col-12">
                    <div class="form-floating">
                        <textarea id="description" name="description" placeholder="Description" class="form-control" style="height: 90px">{{ old('description', $plan->description) }}</textarea>
                        <label for="description">Description (shown on the payment page)</label>
                    </div>
                </div>
            </div>
            <p class="small text-secondary">A new price is put on sale in Stripe as soon as you save. Existing subscribers keep the price they signed up with.</p>

            <h2 class="card-title-sm mb-2">Included reports</h2>
            <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-2">
                @foreach ($reports as $report)
                    <div class="col">
                        <label class="report-tile">
                            <input class="form-check-input mt-0" type="checkbox" name="reports[]" value="{{ $report->id }}" @checked(in_array($report->id, $included))>
                            <span class="min-w-0">
                                <span class="d-block fw-semibold text-truncate">{{ $report->name }}</span>
                                <span class="d-block small text-secondary">{{ $report->code }} · add-on ${{ number_format($report->addon_price, 0) }}/mo</span>
                            </span>
                        </label>
                    </div>
                @endforeach
            </div>
        </div>
        <div class="card-footer bg-white d-flex justify-content-end gap-2">
            <a href="{{ route('admin.plans.index') }}" class="btn btn-light">Cancel</a>
            <button type="submit" class="btn btn-primary">Save plan</button>
        </div>
    </form>
@endsection

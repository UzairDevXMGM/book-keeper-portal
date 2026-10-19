@extends('layouts.app')

@section('title', 'Edit '.$report->name)

@section('content')
    <a href="{{ route('admin.reports.index') }}" class="small"><i class="bi bi-arrow-left me-1"></i>Report catalog</a>
    <div class="page-head mt-1">
        <div>
            <h1 class="h3">{{ $report->name }}</h1>
            <div class="small text-secondary">{{ $report->code }} · {{ $ready ? 'report engine ready' : 'engine not built yet (customers see “coming soon”)' }}</div>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.reports.update', $report) }}" class="card" style="max-width: 820px" novalidate>
        @csrf
        @method('PATCH')
        <div class="card-body p-4">
            <div class="row g-2">
                <div class="col-md-8">
                    <div class="form-floating">
                        <input id="name" name="name" value="{{ old('name', $report->name) }}" placeholder="Name" class="form-control @error('name') is-invalid @enderror" required>
                        <label for="name">Report name</label>
                        @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-floating">
                        <input id="category" name="category" value="{{ old('category', $report->category) }}" placeholder="Category" class="form-control">
                        <label for="category">Category</label>
                    </div>
                </div>
                <div class="col-12">
                    <div class="form-floating">
                        <textarea id="description" name="description" placeholder="Description" class="form-control" style="height: 90px">{{ old('description', $report->description) }}</textarea>
                        <label for="description">Description</label>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-floating">
                        <input id="addon_price" name="addon_price" value="{{ old('addon_price', $report->addon_price) }}" inputmode="decimal" placeholder="0" class="form-control @error('addon_price') is-invalid @enderror" required>
                        <label for="addon_price">Add-on price per month ($)</label>
                        @error('addon_price') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-floating">
                        <input id="sort_order" type="number" name="sort_order" value="{{ old('sort_order', $report->sort_order) }}" placeholder="0" class="form-control" required>
                        <label for="sort_order">Sort order</label>
                    </div>
                </div>
                <div class="col-md-4 d-flex align-items-center">
                    <div class="form-check form-switch ms-md-2">
                        <input type="hidden" name="is_active" value="0">
                        <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" @checked(old('is_active', $report->is_active))>
                        <label class="form-check-label" for="is_active">Offered to customers</label>
                    </div>
                </div>
            </div>
            <p class="small text-secondary mt-2 mb-0">A new add-on price is put on sale in Stripe as soon as you save. Customers who already bought it keep their price.</p>
        </div>
        <div class="card-footer bg-white d-flex justify-content-end gap-2">
            <a href="{{ route('admin.reports.index') }}" class="btn btn-light">Cancel</a>
            <button type="submit" class="btn btn-primary">Save</button>
        </div>
    </form>
@endsection

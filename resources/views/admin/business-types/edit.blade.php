@extends('layouts.app')

@section('title', $type->name)

@section('content')
    <a href="{{ route('admin.business-types.index') }}" class="small"><i class="bi bi-arrow-left me-1"></i>Business types</a>
    <div class="page-head mt-1">
        <div>
            <h1 class="h3">{{ $type->name }}</h1>
            <div class="small text-secondary">Changes apply to statements uploaded from now on, for every customer. Lines already in the books keep their category.</div>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.business-types.update', $type) }}" class="card">
        @csrf
        @method('PATCH')
        <div class="card-body p-4">
            @include('admin.business-types.partials.fields', ['type' => $type])
        </div>
        <div class="card-footer bg-white text-end">
            <button type="submit" class="btn btn-primary">Save</button>
        </div>
    </form>
@endsection

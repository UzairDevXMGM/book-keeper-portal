@extends('layouts.app')

@section('title', 'Business types')

@section('content')
    <div class="page-head">
        <div>
            <h1 class="h3">Business types</h1>
            <div class="small text-secondary">
                {{ number_format($counts['types']) }} kinds of business and {{ number_format($counts['keywords']) }} words that recognise them in a merchant's name
                (“Eppies <b>Restaurant</b>”, “ABC <b>Roofing</b> Corp”). A match gives the payment its category when no merchant rule knows it.
            </div>
        </div>
        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#newTypeModal"><i class="bi bi-plus-lg me-1"></i>Add business type</button>
    </div>

    {{-- Try a name --}}
    <form method="GET" class="card card-body mb-3">
        <label for="test" class="form-label small mb-1 fw-semibold">Try a merchant name</label>
        <div class="d-flex flex-wrap gap-2">
            <input id="test" type="search" name="test" value="{{ $test }}" placeholder="e.g. Gold Coast Heavy Hauling, Inc." class="form-control form-control-sm" style="max-width: 420px">
            <button type="submit" class="btn btn-sm btn-outline-primary">Try</button>
        </div>
        @if ($test !== '')
            <div class="small mt-2">
                @if ($match)
                    <i class="bi bi-check-circle text-success me-1"></i><b>{{ $match['name'] }}</b> (word “{{ $match['phrase'] }}”) →
                    {{ $match['category'] ?? 'no category' }}, {{ $match['confidence'] }}% sure
                @else
                    <i class="bi bi-question-circle text-secondary me-1"></i>No business type recognised: this name would wait on Check categories. Add a word to a type below.
                @endif
            </div>
        @endif
    </form>

    <form method="GET" class="card card-body report-toolbar mb-3 d-flex flex-row flex-wrap align-items-end gap-2">
        <div>
            <label for="q" class="form-label small mb-1">Search</label>
            <input id="q" type="search" name="q" value="{{ $search }}" placeholder="Type or word" class="form-control form-control-sm">
        </div>
        <div>
            <label for="group" class="form-label small mb-1">Group</label>
            <select id="group" name="group" class="form-select form-select-sm">
                <option value="">All groups</option>
                @foreach ($groups as $g)
                    <option value="{{ $g }}" @selected($group === $g)>{{ $g }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-sm btn-primary">Filter</button>
        @if ($search !== '' || $group !== '')
            <a href="{{ route('admin.business-types.index') }}" class="btn btn-sm btn-link">Clear</a>
        @endif
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table report-table align-middle mb-0">
                <thead><tr><th>Business type</th><th>Group</th><th>Category</th><th class="num">Words</th><th class="num">Sure</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($types as $type)
                        @php $words = array_filter(preg_split('/[\r\n,]+/', $type->keywords)); @endphp
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $type->name }}</div>
                                <div class="small text-secondary">{{ \Illuminate\Support\Str::limit(implode(', ', array_slice($words, 0, 8)), 90) }}</div>
                            </td>
                            <td class="small">{{ $type->group }}</td>
                            <td class="small">{{ $type->categoryType?->name ?? '—' }}</td>
                            <td class="num">{{ count($words) }}</td>
                            <td class="num small">{{ $type->confidence }}%</td>
                            <td><span class="badge {{ $type->is_active ? 'text-bg-primary' : 'text-bg-secondary' }}">{{ $type->is_active ? 'On' : 'Off' }}</span></td>
                            <td class="text-end"><a href="{{ route('admin.business-types.edit', $type) }}" class="btn btn-sm btn-link">Edit</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-secondary py-4">No business type matches.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @include('partials.pagination', ['paginator' => $types, 'label' => 'business types'])

    <div class="modal fade" id="newTypeModal" tabindex="-1" aria-labelledby="newTypeTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <form method="POST" action="{{ route('admin.business-types.store') }}" class="modal-content">
                @csrf
                <div class="modal-header">
                    <h2 class="modal-title h5" id="newTypeTitle">Add business type</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    @include('admin.business-types.partials.fields', ['type' => new \App\Models\BusinessType(['direction' => 'out', 'confidence' => 72, 'is_active' => true])])
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add</button>
                </div>
            </form>
        </div>
    </div>
@endsection

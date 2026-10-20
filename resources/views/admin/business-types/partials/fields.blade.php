{{-- Fields shared by "Add business type" and "Edit" --}}
<div class="row g-3">
    <div class="col-md-6">
        <label for="bt-name" class="form-label small">Business type</label>
        <input id="bt-name" type="text" name="name" value="{{ old('name', $type->name) }}" class="form-control @error('name') is-invalid @enderror" placeholder="e.g. Roofing contractor" required>
        @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <div class="col-md-6">
        <label for="bt-group" class="form-label small">Group</label>
        <input id="bt-group" type="text" name="group" value="{{ old('group', $type->group) }}" class="form-control" list="bt-groups" placeholder="e.g. Construction & home services">
        <datalist id="bt-groups">
            @foreach ($groups as $g)
                <option value="{{ $g }}"></option>
            @endforeach
        </datalist>
    </div>
    <div class="col-md-6">
        <label for="bt-category" class="form-label small">Payments to it go to</label>
        <select id="bt-category" name="category_type_id" class="form-select">
            <option value="">No category (only show the type)</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}" @selected((int) old('category_type_id', $type->category_type_id) === $category->id)>{{ $category->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-3">
        <label for="bt-confidence" class="form-label small">How sure (%)</label>
        <input id="bt-confidence" type="number" name="confidence" min="40" max="95" value="{{ old('confidence', $type->confidence) }}" class="form-control">
    </div>
    <div class="col-md-3">
        <label for="bt-direction" class="form-label small">Money</label>
        <select id="bt-direction" name="direction" class="form-select">
            @foreach (['out' => 'Money out', 'in' => 'Money in', 'any' => 'In or out'] as $value => $label)
                <option value="{{ $value }}" @selected(old('direction', $type->direction) === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-12">
        <label for="bt-keywords" class="form-label small">Words in the merchant's name (one per line)</label>
        <textarea id="bt-keywords" name="keywords" rows="8" class="form-control font-monospace small @error('keywords') is-invalid @enderror" placeholder="ROOFING&#10;ROOFERS&#10;GUTTERS">{{ old('keywords', $type->keywords) }}</textarea>
        @error('keywords') <div class="invalid-feedback">{{ $message }}</div> @enderror
        <div class="form-text">Whole words only: “ROOF” finds “ABC Roof Co” but not “Roofline”. When several types match, the longest word wins (“HEAVY HAULING” beats “GOLD”). Below the review threshold, a match is always checked by the customer.</div>
    </div>
    <div class="col-12">
        <div class="form-check form-switch">
            <input type="hidden" name="is_active" value="0">
            <input class="form-check-input" type="checkbox" role="switch" name="is_active" value="1" id="bt-active" @checked(old('is_active', $type->is_active))>
            <label class="form-check-label small" for="bt-active">In use</label>
        </div>
    </div>
</div>

{{-- "Posts to" select: a master chart account or a system account. Expects $accounts (grouped by section), $selected, $id. --}}
<select id="{{ $id }}" name="target" class="form-select @error('target', $bag ?? 'default') is-invalid @enderror" required>
    <optgroup label="System accounts">
        @foreach (\App\Http\Controllers\Admin\CategoryTypeController::SYSTEM_TARGETS as $key => $label)
            <option value="{{ $key }}" @selected($selected === $key)>{{ $label }}</option>
        @endforeach
    </optgroup>
    @foreach ($accounts as $section => $list)
        <optgroup label="{{ $section }}">
            @foreach ($list as $account)
                <option value="{{ $account->code }}" @selected((string) $selected === (string) $account->code)>{{ $account->code }} · {{ $account->name }}</option>
            @endforeach
        </optgroup>
    @endforeach
</select>

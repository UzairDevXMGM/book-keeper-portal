{{-- One journal line. Field names are set by app.js (lines[i][field]); data-name is the field. --}}
<tr>
    <td>
        <select class="form-select form-select-sm" data-name="account_id" aria-label="Account">
            <option value="">Choose account...</option>
            @foreach ($grouped as $type => $typeAccounts)
                <optgroup label="{{ $typeLabels[$type] ?? $type }}">
                    @foreach ($typeAccounts as $account)
                        <option value="{{ $account->id }}" @selected((int) ($line['account_id'] ?? 0) === $account->id)>{{ $account->code }} · {{ $account->name }}</option>
                    @endforeach
                </optgroup>
            @endforeach
        </select>
    </td>
    <td><input class="form-control form-control-sm" data-name="description" value="{{ $line['description'] ?? '' }}" maxlength="255" aria-label="Line description"></td>
    <td><input class="form-control form-control-sm text-end" data-name="debit" value="{{ $line['debit'] ?? '' }}" inputmode="decimal" placeholder="0.00" aria-label="Debit"></td>
    <td><input class="form-control form-control-sm text-end" data-name="credit" value="{{ $line['credit'] ?? '' }}" inputmode="decimal" placeholder="0.00" aria-label="Credit"></td>
    <td><button type="button" class="btn btn-sm btn-link text-secondary" data-remove-line aria-label="Remove line"><i class="bi bi-x-lg"></i></button></td>
</tr>

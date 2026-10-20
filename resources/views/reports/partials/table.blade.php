{{-- Renders one ReportDocument table. Expects $table. --}}
@php
    // Ledger-style tables (debit + credit columns) get a light red / green tint on those two columns
    $drCr = count(array_intersect(array_column($table['columns'], 'key'), ['debit', 'credit'])) === 2;
@endphp
<div class="card mb-3">
    @if ($table['title'])
        <div class="card-header bg-white fw-semibold">{{ $table['title'] }}</div>
    @endif
    <div class="table-responsive">
        <table @class(['table report-table mb-0', 'is-drcr' => $drCr])>
            <thead>
                <tr>
                    @foreach ($table['columns'] as $column)
                        <th scope="col" @class(['num' => $column['format'] !== 'text', 'col-'.$column['key'] => $drCr])>{{ $column['label'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($table['rows'] as $row)
                    @php
                        $linkKey = $row['href'] ? (array_key_exists('entry', $row['cells']) ? 'entry' : $table['columns'][0]['key']) : null;
                    @endphp
                    <tr class="row-{{ $row['style'] }}">
                        @if ($row['style'] === 'heading' && count(array_filter($row['cells'], fn ($v) => $v !== null && $v !== '')) === 1 && filled($row['cells'][$table['columns'][0]['key']] ?? null) && ! $row['href'])
                            {{-- A heading with only a label (e.g. the account name in the ledger) spans the whole row --}}
                            <td colspan="{{ count($table['columns']) }}">{{ $row['cells'][$table['columns'][0]['key']] }}</td>
                        @else
                        @foreach ($table['columns'] as $i => $column)
                            @php
                                $value = $row['cells'][$column['key']] ?? null;
                                $text = match (true) {
                                    $value === null => '',
                                    $column['format'] === 'money' => money($value),
                                    $column['format'] === 'pct' => (is_numeric($value) ? ($value > 0 ? '+' : '').pct($value) : $value),
                                    $column['format'] === 'percent' => (is_numeric($value) ? pct($value) : $value),
                                    $column['format'] === 'number' => number_format((float) $value),
                                    default => $value,
                                };
                            @endphp
                            <td @class([
                                'num' => $column['format'] !== 'text',
                                'col-'.$column['key'] => $drCr,
                                'indent-1' => $i === 0 && $row['indent'] > 0,
                                'neg' => in_array($column['format'], ['money', 'pct', 'percent'], true) && is_numeric($value) && $value < 0,
                            ])>
                                @if ($linkKey === $column['key'] && $text !== '')
                                    <a href="{{ $row['href'] }}">{{ $text }}</a>
                                @else
                                    {{ $text }}
                                @endif
                            </td>
                        @endforeach
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ count($table['columns']) }}" class="text-center text-secondary py-4">{{ $table['empty'] }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

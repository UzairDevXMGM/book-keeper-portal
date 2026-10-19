{{-- Renders one ReportDocument table. Expects $table. --}}
<div class="card mb-3">
    @if ($table['title'])
        <div class="card-header bg-white fw-semibold">{{ $table['title'] }}</div>
    @endif
    <div class="table-responsive">
        <table class="table report-table mb-0">
            <thead>
                <tr>
                    @foreach ($table['columns'] as $column)
                        <th scope="col" @class(['num' => $column['format'] !== 'text'])>{{ $column['label'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($table['rows'] as $row)
                    @php
                        $linkKey = $row['href'] ? (array_key_exists('entry', $row['cells']) ? 'entry' : $table['columns'][0]['key']) : null;
                    @endphp
                    <tr class="row-{{ $row['style'] }}">
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
                    </tr>
                @empty
                    <tr><td colspan="{{ count($table['columns']) }}" class="text-center text-secondary py-4">{{ $table['empty'] }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

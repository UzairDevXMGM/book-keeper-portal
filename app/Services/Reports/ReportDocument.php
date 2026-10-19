<?php

namespace App\Services\Reports;

/**
 * What every report engine returns: KPI tiles, tables and notes. One Blade partial renders any report and one CSV
 * writer exports any report, so a new report only has to build this structure.
 *
 * Table rows: ['cells' => [columnKey => value], 'style' => line|heading|subtotal|total, 'indent' => 0|1, 'href' => ?url]
 */
class ReportDocument
{
    /** @var list<array{label: string, value: mixed, format: string, tone: ?string, sub: ?string}> */
    public array $kpis = [];

    /** @var list<array{title: ?string, columns: list<array{key: string, label: string, format: string}>, rows: list<array>, empty: string}> */
    public array $tables = [];

    /** @var list<array{label: string, ok: bool}> */
    public array $checks = [];

    /** @var list<string> */
    public array $notes = [];

    public function __construct(public string $title, public string $subtitle = '')
    {
    }

    public function kpi(string $label, mixed $value, string $format = 'money', ?string $tone = null, ?string $sub = null): static
    {
        $this->kpis[] = compact('label', 'value', 'format', 'tone', 'sub');

        return $this;
    }

    public function table(array $columns, array $rows, ?string $title = null, string $empty = 'No transactions in this period.'): static
    {
        $this->tables[] = compact('title', 'columns', 'rows', 'empty');

        return $this;
    }

    public function check(string $label, bool $ok): static
    {
        $this->checks[] = compact('label', 'ok');

        return $this;
    }

    public function note(string $note): static
    {
        $this->notes[] = $note;

        return $this;
    }

    public static function column(string $key, string $label, string $format = 'text'): array
    {
        return compact('key', 'label', 'format');
    }

    public static function row(array $cells, string $style = 'line', int $indent = 0, ?string $href = null): array
    {
        return compact('cells', 'style', 'indent', 'href');
    }

    /** Flat rows for CSV export. */
    public function toCsvRows(): array
    {
        $out = [[$this->title], [$this->subtitle], []];
        foreach ($this->kpis as $kpi) {
            $out[] = [$kpi['label'], is_numeric($kpi['value']) ? round((float) $kpi['value'], 2) : $kpi['value']];
        }
        foreach ($this->tables as $table) {
            $out[] = [];
            if ($table['title']) {
                $out[] = [$table['title']];
            }
            $out[] = array_column($table['columns'], 'label');
            foreach ($table['rows'] as $row) {
                $out[] = array_map(function ($column) use ($row) {
                    $value = $row['cells'][$column['key']] ?? '';

                    return in_array($column['format'], ['money', 'pct', 'percent', 'number'], true) && is_numeric($value)
                        ? number_format((float) $value, 2, '.', '')
                        : self::safeText((string) $value);
                }, $table['columns']);
            }
        }

        return $out;
    }

    /**
     * Text from bank statements ends up in the CSV. A cell that starts with = + - @ would run as a formula when the
     * file is opened in Excel, so such text gets a leading apostrophe (Excel shows it as plain text).
     */
    public static function safeText(string $value): string
    {
        return $value !== '' && ! is_numeric($value) && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}

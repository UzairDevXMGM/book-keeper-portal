<?php

namespace Tests\Support;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/** Builds small statement files (CSV, XLSX, text PDF) for tests. */
class Statements
{
    public static function xlsx(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'stmt').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return (string) file_get_contents($path);
    }

    /** A one-page PDF whose text is the given lines (what a bank's text-based PDF statement looks like to a parser). */
    public static function pdf(array $lines): string
    {
        $stream = "BT /F1 10 Tf 40 800 Td 14 TL\n";
        foreach ($lines as $line) {
            $stream .= '('.self::escape($line).") Tj T*\n";
        }

        return self::document($stream.'ET');
    }

    /**
     * A one-page PDF with text placed at exact positions, like a statement laid out in columns:
     * [y => [[x, 'text'], [x, 'text'], ...], ...] (PDF coordinates: y grows upwards).
     */
    public static function positionedPdf(array $rows): string
    {
        $stream = '';
        foreach ($rows as $y => $pieces) {
            foreach ($pieces as [$x, $text]) {
                $stream .= "BT /F1 9 Tf {$x} {$y} Td (".self::escape($text).") Tj ET\n";
            }
        }

        return self::document($stream);
    }

    private static function escape(string $text): string
    {
        return strtr($text, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }

    private static function document(string $stream): string
    {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }
}

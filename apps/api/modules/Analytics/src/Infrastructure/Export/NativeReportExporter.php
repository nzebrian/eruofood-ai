<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Infrastructure\Export;

use EruoFood\Analytics\Application\DTO\ExportResult;
use EruoFood\Analytics\Application\Port\ReportExporter;
use EruoFood\Analytics\Domain\Enum\ExportFormat;
use EruoFood\Analytics\Domain\Report\Report;
use ZipArchive;

/**
 * Serialises a report to CSV, XLSX or PDF with no third-party dependency:
 *   - CSV  — native, via fputcsv.
 *   - XLSX — a minimal but valid Office Open XML workbook, zipped with the
 *            built-in ZipArchive (falls back to CSV bytes if zip is unavailable).
 *   - PDF  — a minimal but valid single-page PDF with a correct xref table.
 * These cover the export/scheduled-report flows offline; richer styling can be
 * added behind the same {@see ReportExporter} port.
 */
final class NativeReportExporter implements ReportExporter
{
    public function export(Report $report, ExportFormat $format): ExportResult
    {
        $filename = sprintf('%s-%s.%s', $report->key(), $report->range()->fromDate(), $format->extension());

        $content = match ($format) {
            ExportFormat::Csv => $this->csv($report),
            ExportFormat::Xlsx => $this->xlsx($report),
            ExportFormat::Pdf => $this->pdf($report),
        };

        return new ExportResult($filename, $format->mimeType(), $content);
    }

    private function csv(Report $report): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }
        // Explicit escape ('') keeps this RFC-4180 compliant and silences the
        // PHP 8.4 deprecation of the implicit escape-character default.
        fputcsv($handle, $report->columns(), ',', '"', '');
        foreach ($report->rows() as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }
        rewind($handle);
        $out = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $out;
    }

    private function xlsx(Report $report): string
    {
        if (! class_exists(ZipArchive::class)) {
            return $this->csv($report); // graceful fallback
        }

        $rows = array_merge([$report->columns()], $report->rows());
        $sheetRows = '';
        foreach ($rows as $r => $cells) {
            $cellsXml = '';
            foreach (array_values($cells) as $c => $value) {
                $ref = $this->cellRef($c, $r + 1);
                if (is_int($value) || is_float($value)) {
                    $cellsXml .= sprintf('<c r="%s"><v>%s</v></c>', $ref, $value);
                } else {
                    $cellsXml .= sprintf('<c r="%s" t="inlineStr"><is><t>%s</t></is></c>', $ref, htmlspecialchars((string) $value, ENT_XML1));
                }
            }
            $sheetRows .= sprintf('<row r="%d">%s</row>', $r + 1, $cellsXml);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($tmp === false) {
            return $this->csv($report);
        }
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheetRows.'</sheetData></worksheet>');
        $zip->close();
        $content = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $content;
    }

    private function pdf(Report $report): string
    {
        $lines = [$report->title(), implode('  |  ', $report->columns())];
        foreach ($report->rows() as $row) {
            $lines[] = implode('  |  ', array_map('strval', $row));
        }

        // Build the page content stream (text lines).
        $text = "BT /F1 11 Tf 40 800 Td 14 TL\n";
        foreach ($lines as $line) {
            $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
            $text .= '('.$escaped.") Tj T*\n";
        }
        $text .= 'ET';

        $objects = [
            '<</Type/Catalog/Pages 2 0 R>>',
            '<</Type/Pages/Kids[3 0 R]/Count 1>>',
            '<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>',
            '<</Length '.strlen($text).'>>stream'."\n".$text."\nendstream",
            '<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[$i] = strlen($pdf);
            $pdf .= ($i + 1).' 0 obj'."\n".$body."\nendobj\n";
        }
        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= 'trailer<</Size '.(count($objects) + 1).'/Root 1 0 R>>'."\nstartxref\n".$xrefPos."\n%%EOF";

        return $pdf;
    }

    private function cellRef(int $col, int $row): string
    {
        $letter = '';
        $col++;
        while ($col > 0) {
            $col--;
            $letter = chr(65 + ($col % 26)).$letter;
            $col = intdiv($col, 26);
        }

        return $letter.$row;
    }
}

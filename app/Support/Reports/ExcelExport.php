<?php

declare(strict_types=1);

namespace App\Support\Reports;

use DateTimeImmutable;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * Single native OpenXML (.xlsx) writer for every report export. No
 * external dependencies; the response streams a temp zip file.
 *
 * - Money: callers pass ExcelExport::money($minor) floats with the
 *   MONEY column format (conversion minor/100 lives only here).
 * - Percent: callers pass a 0-1 ratio float with the PCT format.
 * - Dates: callers pass 'Y-m-d' strings with the DATE format, stored
 *   as real Excel serial dates.
 * - Formula injection: text starting with = + - @ gets an apostrophe.
 * - Arabic locale passes $rtl=true for a right-to-left sheet.
 */
final class ExcelExport
{
    public const TEXT = 'text';

    public const INT = 'int';

    public const MONEY = 'money';

    public const PCT = 'pct';

    public const DATE = 'date';

    public static function escape(string|int|float|null $value): string
    {
        if ($value === null) {
            return '';
        }

        $text = (string) $value;

        if ($text !== '' && in_array($text[0], ['=', '+', '-', '@'], true)) {
            return "'".$text;
        }

        return $text;
    }

    public static function money(int $minor): float
    {
        return round($minor / 100, 2);
    }

    /**
     * @param  array<string, string>  $meta  Title/filter rows above the table.
     * @param  array<int, string>  $columns  Header labels.
     * @param  iterable<array<int, mixed>>  $rows  Floats for MONEY/PCT, 'Y-m-d' for DATE, int, string.
     * @param  array<int, string>  $formats  Column formats, defaults to TEXT.
     */
    public static function download(
        string $filename,
        string $title,
        array $meta,
        array $columns,
        iterable $rows,
        array $formats = [],
        bool $rtl = false
    ): StreamedResponse {
        $clean = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $filename).'.xlsx';

        return new StreamedResponse(function () use ($title, $meta, $columns, $rows, $formats, $rtl): void {
            $path = tempnam(sys_get_temp_dir(), 'xlsx_');

            if ($path === false) {
                return;
            }

            $zip = new ZipArchive;
            $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $zip->addFromString('[Content_Types].xml', self::contentTypes());
            $zip->addFromString('_rels/.rels', self::rootRels());
            $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRels());
            $zip->addFromString('xl/workbook.xml', self::workbook());
            $zip->addFromString('xl/styles.xml', self::styles());
            $zip->addFromString('xl/worksheets/sheet1.xml', self::sheet($title, $meta, $columns, $rows, $formats, $rtl));
            $zip->close();

            readfile($path);
            @unlink($path);
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$clean.'"',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    public static function dateSerial(string $date): float
    {
        $day = new DateTimeImmutable($date.' UTC');
        $epoch = new DateTimeImmutable('1899-12-30 UTC');

        return round(($day->getTimestamp() - $epoch->getTimestamp()) / 86400, 0);
    }

    /**
     * @param  array<string, string>  $meta
     * @param  array<int, string>  $columns
     * @param  iterable<array<int, mixed>>  $rows
     * @param  array<int, string>  $formats
     */
    private static function sheet(string $title, array $meta, array $columns, iterable $rows, array $formats, bool $rtl): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'."\n";

        if ($rtl) {
            $xml .= '    <sheetViews><sheetView tabSelected="1" workbookViewId="0" rightToLeft="1"/></sheetViews>'."\n";
        }

        $xml .= '    <sheetData>'."\n";
        $line = 1;
        $xml .= '        <row r="'.$line.'"><c r="A'.$line.'" t="inlineStr" s="1"><is><t>'
            .htmlspecialchars(self::escape($title), ENT_XML1).'</t></is></c></row>'."\n";
        $line++;

        foreach ($meta as $label => $value) {
            $xml .= '        <row r="'.$line.'">'
                .'<c r="A'.$line.'" t="inlineStr" s="1"><is><t>'.htmlspecialchars($label, ENT_XML1).'</t></is></c>'
                .'<c r="B'.$line.'" t="inlineStr"><is><t>'.htmlspecialchars(self::escape($value), ENT_XML1).'</t></is></c>'
                .'</row>'."\n";
            $line++;
        }

        $line++;

        $xml .= '        <row r="'.$line.'">';
        foreach ($columns as $i => $column) {
            $xml .= '<c r="'.self::cell($i).$line.'" t="inlineStr" s="1"><is><t>'
                .htmlspecialchars(self::escape($column), ENT_XML1).'</t></is></c>';
        }
        $xml .= '</row>'."\n";
        $line++;

        foreach ($rows as $row) {
            $xml .= '        <row r="'.$line.'">';
            foreach ($row as $i => $value) {
                $xml .= self::cellXml(self::cell($i).$line, $value, $formats[$i] ?? self::TEXT);
            }
            $xml .= '</row>'."\n";
            $line++;
        }

        return $xml.'    </sheetData>'."\n".'</worksheet>';
    }

    private static function cellXml(string $ref, mixed $value, string $format): string
    {
        if ($format === self::MONEY && is_numeric($value)) {
            return '<c r="'.$ref.'" s="3"><v>'.((float) $value).'</v></c>';
        }

        if ($format === self::PCT && is_numeric($value)) {
            return '<c r="'.$ref.'" s="4"><v>'.((float) $value).'</v></c>';
        }

        if ($format === self::DATE && is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return '<c r="'.$ref.'" s="5"><v>'.self::dateSerial($value).'</v></c>';
        }

        if ($format === self::INT && is_numeric($value)) {
            return '<c r="'.$ref.'" s="2"><v>'.((int) $value).'</v></c>';
        }

        if ($value === null || $value === '') {
            return '<c r="'.$ref.'"/>';
        }

        if (is_int($value) || is_float($value)) {
            return '<c r="'.$ref.'" s="2"><v>'.$value.'</v></c>';
        }

        return '<c r="'.$ref.'" t="inlineStr"><is><t>'
            .htmlspecialchars(self::escape((string) $value), ENT_XML1).'</t></is></c>';
    }

    private static function cell(int $index): string
    {
        $letter = '';

        while ($index >= 0) {
            $letter = chr(($index % 26) + 65).$letter;
            $index = intdiv($index, 26) - 1;
        }

        return $letter;
    }

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }

    private static function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private static function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    private static function workbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>';
    }

    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="3">'
            .'<numFmt numFmtId="164" formatCode="#,##0.00"/>'
            .'<numFmt numFmtId="165" formatCode="0.00%"/>'
            .'<numFmt numFmtId="166" formatCode="yyyy-mm-dd"/>'
            .'</numFmts>'
            .'<fonts count="2"><font><name val="Calibri"/><sz val="11"/></font><font><b/><name val="Calibri"/><sz val="11"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="6">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'</cellXfs></styleSheet>';
    }
}

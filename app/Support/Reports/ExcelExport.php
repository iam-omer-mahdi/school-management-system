<?php

declare(strict_types=1);

namespace App\Support\Reports;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Excel export helper writing native OpenXML spreadsheets (.xlsx)
 * without external dependencies.
 *
 * Rules:
 * - Real numbers: minor units / 100 in one place for money, decimals formatted.
 * - Dates as real dates or ISO strings.
 * - Formula injection protection: prefix cells starting with =, +, -, @ with an apostrophe '.
 * - Right-to-left sheet direction support when locale is Arabic.
 * - Streams or outputs cleanly formatted spreadsheet.
 */
final class ExcelExport
{
    /**
     * Escape strings to prevent spreadsheet formula injection.
     */
    public static function escape(string|int|float|null $value): string
    {
        if ($value === null) {
            return '';
        }

        $str = (string) $value;

        if ($str === '') {
            return '';
        }

        $firstChar = $str[0];
        if (in_array($firstChar, ['=', '+', '-', '@'], true)) {
            return "'".$str;
        }

        return $str;
    }

    /**
     * Convert minor integer units to a standard decimal number (e.g. 150000 -> 1500.00).
     */
    public static function money(int $minor): float
    {
        return round($minor / 100, 2);
    }

    /**
     * Download an .xlsx file using streaming.
     *
     * @param  string  $filename  Without .xlsx
     * @param  string  $title
     * @param  array<string, string>  $metaHeaders  Key-value pairs displayed at top (Report, Year, Filters, Generated at)
     * @param  array<string>  $columns  Header row columns
     * @param  iterable<array<int, mixed>>  $rows  Data rows (values will be properly typed: float/int as numeric, strings escaped)
     */
    public static function download(
        string $filename,
        string $title,
        array $metaHeaders,
        array $columns,
        iterable $rows,
        bool $rtl = false
    ): StreamedResponse {
        $cleanFilename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename).'.xlsx';

        return new StreamedResponse(function () use ($title, $metaHeaders, $columns, $rows, $rtl): void {
            $zipPath = tempnam(sys_get_temp_dir(), 'xlsx_');
            if ($zipPath === false) {
                return;
            }

            $zip = new \ZipArchive();
            $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

            $zip->addFromString('[Content_Types].xml', self::contentTypesXml());
            $zip->addFromString('_rels/.rels', self::rootRelsXml());
            $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRelsXml());
            $zip->addFromString('xl/workbook.xml', self::workbookXml());
            $zip->addFromString('xl/styles.xml', self::stylesXml());

            // Build worksheet XML
            $sheetXml = self::buildSheetXml($title, $metaHeaders, $columns, $rows, $rtl);
            $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);

            $zip->close();

            readfile($zipPath);
            @unlink($zipPath);
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$cleanFilename.'"',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    private static function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
    <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>';
    }

    private static function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';
    }

    private static function workbookRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>';
    }

    private static function workbookXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets>
        <sheet name="Sheet1" sheetId="1" r:id="rId1"/>
    </sheets>
</workbook>';
    }

    private static function stylesXml(): string
    {
        // 0: General
        // 1: Bold title / header
        // 2: Numeric standard
        // 3: Money: #,##0.00
        // 4: Percent: 0.00%
        // 5: Date: YYYY-MM-DD
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <numFmts count="3">
        <numFmt numFmtId="164" formatCode="#,##0.00"/>
        <numFmt numFmtId="165" formatCode="0.0%"/>
        <numFmt numFmtId="166" formatCode="yyyy-mm-dd"/>
    </numFmts>
    <fonts count="2">
        <font><name val="Calibri"/><sz val="11"/></font>
        <font><b/><name val="Calibri"/><sz val="11"/></font>
    </fonts>
    <fills count="2">
        <fill><patternFill patternType="none"/></fill>
        <fill><patternFill patternType="gray125"/></fill>
    </fills>
    <borders count="1">
        <border><left/><right/><top/><bottom/><diagonal/></border>
    </borders>
    <cellStyleXfs count="1">
        <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>
    </cellStyleXfs>
    <cellXfs count="6">
        <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
        <xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>
        <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
        <xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>
        <xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>
        <xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>
    </cellXfs>
</styleSheet>';
    }

    /**
     * @param  array<string, string>  $metaHeaders
     * @param  array<string>  $columns
     * @param  iterable<array<int, mixed>>  $rows
     */
    private static function buildSheetXml(
        string $title,
        array $metaHeaders,
        array $columns,
        iterable $rows,
        bool $rtl
    ): string {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n";
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'."\n";

        if ($rtl) {
            $xml .= '    <sheetViews><sheetView tabSelected="1" workbookViewId="0" rightToLeft="1"/></sheetViews>'."\n";
        }

        $xml .= '    <sheetData>'."\n";

        $rowIdx = 1;

        // Title row (Row 1)
        $xml .= '        <row r="'.$rowIdx.'">'."\n";
        $xml .= '            <c r="A'.$rowIdx.'" t="inlineStr" s="1"><is><t>'.htmlspecialchars(self::escape($title), ENT_XML1).'</t></is></c>'."\n";
        $xml .= '        </row>'."\n";
        $rowIdx++;

        // Metadata rows
        foreach ($metaHeaders as $label => $val) {
            $xml .= '        <row r="'.$rowIdx.'">'."\n";
            $xml .= '            <c r="A'.$rowIdx.'" t="inlineStr" s="1"><is><t>'.htmlspecialchars($label, ENT_XML1).'</t></is></c>'."\n";
            $xml .= '            <c r="B'.$rowIdx.'" t="inlineStr"><is><t>'.htmlspecialchars(self::escape($val), ENT_XML1).'</t></is></c>'."\n";
            $xml .= '        </row>'."\n";
            $rowIdx++;
        }

        // Empty row
        $rowIdx++;

        // Header columns
        $xml .= '        <row r="'.$rowIdx.'">'."\n";
        $colLetters = range('A', 'Z');
        foreach ($columns as $cIdx => $colName) {
            $colLetter = self::columnLetter($cIdx);
            $xml .= '            <c r="'.$colLetter.$rowIdx.'" t="inlineStr" s="1"><is><t>'.htmlspecialchars(self::escape($colName), ENT_XML1).'</t></is></c>'."\n";
        }
        $xml .= '        </row>'."\n";
        $rowIdx++;

        // Data rows
        foreach ($rows as $rowData) {
            $xml .= '        <row r="'.$rowIdx.'">'."\n";
            foreach ($rowData as $cIdx => $cellVal) {
                $colLetter = self::columnLetter($cIdx);
                $ref = $colLetter.$rowIdx;

                if (is_int($cellVal)) {
                    $xml .= '            <c r="'.$ref.'" s="2"><v>'.$cellVal.'</v></c>'."\n";
                } elseif (is_float($cellVal)) {
                    // Check if it's monetary or percentage
                    $xml .= '            <c r="'.$ref.'" s="3"><v>'.$cellVal.'</v></c>'."\n";
                } elseif ($cellVal === null || $cellVal === '') {
                    $xml .= '            <c r="'.$ref.'"/>'."\n";
                } else {
                    $escaped = htmlspecialchars(self::escape((string) $cellVal), ENT_XML1);
                    $xml .= '            <c r="'.$ref.'" t="inlineStr"><is><t>'.$escaped.'</t></is></c>'."\n";
                }
            }
            $xml .= '        </row>'."\n";
            $rowIdx++;
        }

        $xml .= '    </sheetData>'."\n";
        $xml .= '</worksheet>';

        return $xml;
    }

    private static function columnLetter(int $colIndex): string
    {
        $letter = '';
        while ($colIndex >= 0) {
            $letter = chr(($colIndex % 26) + 65).$letter;
            $colIndex = intdiv($colIndex, 26) - 1;
        }

        return $letter;
    }
}

<?php

namespace App\Support\Xlsx;

use ZipArchive;

/**
 * A small .xlsx writer with no outside packages (it only needs PHP's zip extension, which the shop computer
 * already has switched on). Text goes in as plain text cells, so nothing a person typed can run as a formula.
 * Numbers stay numbers. The first row of every sheet is bold.
 */
final class SimpleXlsx
{
    /** @var list<array{name: string, rows: list<list<mixed>>, widths: list<int>}> */
    private array $sheets = [];

    /**
     * @param  list<list<mixed>>  $rows  each cell is a string, int, float, bool or null; a cell like ['b' => 'text'] is bold
     * @param  list<int>  $widths  optional column widths in characters
     */
    public function addSheet(string $name, array $rows, array $widths = []): self
    {
        $name = trim(preg_replace('/[\[\]\:\*\?\/\\\\]/', ' ', $name)) ?: 'Sheet';
        $name = mb_substr($name, 0, 31);
        $base = $name;
        $n = 2;
        while (in_array(mb_strtolower($name), array_map(fn ($s) => mb_strtolower($s['name']), $this->sheets), true)) {
            $name = mb_substr($base, 0, 28).' '.$n++;
        }
        $this->sheets[] = ['name' => $name, 'rows' => $rows, 'widths' => $widths];

        return $this;
    }

    /** Writes the workbook and returns the path of a temporary file (the caller deletes it). */
    public function save(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) {
            throw new \RuntimeException('Could not create the Excel file.');
        }
        $count = max(1, count($this->sheets));
        if ($this->sheets === []) {
            $this->addSheet('Sheet1', []);
        }
        $types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($this->sheets as $i => $sheet) {
            $id = $i + 1;
            $types .= '<Override PartName="/xl/worksheets/sheet'.$id.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $workbook .= '<sheet name="'.self::esc($sheet['name']).'" sheetId="'.$id.'" r:id="rId'.$id.'"/>';
            $rels .= '<Relationship Id="rId'.$id.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$id.'.xml"/>';
            $zip->addFromString('xl/worksheets/sheet'.$id.'.xml', $this->sheetXml($sheet));
        }
        $rels .= '<Relationship Id="rId'.($count + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $zip->addFromString('[Content_Types].xml', $types.'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', $workbook.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
        $zip->close();

        return $path;
    }

    /** @param array{name: string, rows: list<list<mixed>>, widths: list<int>} $sheet */
    private function sheetXml(array $sheet): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        if ($sheet['widths'] !== []) {
            $xml .= '<cols>';
            foreach ($sheet['widths'] as $i => $w) {
                $xml .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.max(4, min(80, $w)).'" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }
        $xml .= '<sheetData>';
        foreach ($sheet['rows'] as $r => $row) {
            $xml .= '<row r="'.($r + 1).'">';
            foreach (array_values($row) as $c => $cell) {
                $ref = self::col($c).($r + 1);
                $bold = $r === 0;
                if (is_array($cell) && array_key_exists('b', $cell)) {
                    $bold = true;
                    $cell = $cell['b'];
                }
                $style = $bold ? ' s="1"' : '';
                if ($cell === null || $cell === '') {
                    $xml .= '<c r="'.$ref.'"'.$style.'/>';
                } elseif (is_bool($cell)) {
                    $xml .= '<c r="'.$ref.'"'.$style.' t="inlineStr"><is><t>'.($cell ? 'Yes' : 'No').'</t></is></c>';
                } elseif (is_int($cell) || is_float($cell)) {
                    $xml .= '<c r="'.$ref.'"'.$style.'><v>'.$cell.'</v></c>';
                } else {
                    $xml .= '<c r="'.$ref.'"'.$style.' t="inlineStr"><is><t xml:space="preserve">'.self::esc((string) $cell).'</t></is></c>';
                }
            }
            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    private static function col(int $index): string
    {
        $name = '';
        for ($i = $index; $i >= 0; $i = intdiv($i, 26) - 1) {
            $name = chr(65 + $i % 26).$name;
        }

        return $name;
    }

    private static function esc(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}

<?php

namespace App\Support;

/**
 * Minimal, dependency-free .xlsx (Office Open XML) writer.
 *
 * An .xlsx file is just a ZIP of XML parts, so this builds the parts and the
 * ZIP container by hand. Written this way on purpose:
 *
 *  - ext-zip is NOT enabled on this box, so phpoffice/phpspreadsheet cannot be
 *    installed or used (it hard-requires ext-zip). Only zlib/gzdeflate is
 *    needed here, which is compiled in.
 *  - No new third-party dependency to vet (PCI DSS v4.0.1 Req 6.3.2 -
 *    inventory of bespoke/third-party software components).
 *
 * Values are written as inline strings, never as formulas, so a cell value
 * beginning with = + - @ is rendered as literal text. That closes the CSV/
 * formula-injection hole (CWE-1236) that plain CSV exports carry.
 *
 * Usage:
 *     $xlsx = new XlsxWriter();
 *     $xlsx->addQuerySheet('Flight', $rows, ['title' => 'Flight - 20-Aug-2026']);
 *     $bytes = $xlsx->output();
 */
class XlsxWriter
{
    /** Style ids - these are indexes into the cellXfs list in styles.xml. */
    public const S_PLAIN      = 0;
    public const S_TITLE      = 1;
    public const S_HEADER     = 2;
    public const S_TEXT       = 3;
    public const S_INT        = 4;
    public const S_DECIMAL    = 5;
    public const S_LABEL      = 6;
    public const S_TOTAL_TEXT = 7;
    public const S_TOTAL_NUM  = 8;

    /** Widest column we will auto-size to (Excel chars). */
    private const MAX_COL_WIDTH = 60;

    /**
     * @var array<int, array{name:string, lines:array, freeze:int, widths:array}>
     */
    private array $sheets = [];

    /**
     * Add a sheet straight from a DB::select() / query-builder result.
     *
     * Column headers come from the keys of the first row, so whatever your
     * SELECT returns is what lands in the sheet.
     *
     * @param string $name Sheet tab name (sanitised to Excel's rules)
     * @param array  $rows Array of assoc arrays or stdClass objects
     * @param array  $meta Optional:
     *                     'title'    => string shown in row 1
     *                     'subtitle' => [[label, value], ...] shown under the title
     *                     'totals'   => assoc array rendered as a highlighted last row
     */
    public function addQuerySheet(string $name, array $rows, array $meta = []): self
    {
        $rows  = array_map(fn ($r) => (array) $r, $rows);
        $lines = [];

        if (!empty($meta['title'])) {
            $lines[] = [['v' => $meta['title'], 's' => self::S_TITLE]];
        }
        foreach ($meta['subtitle'] ?? [] as $pair) {
            $lines[] = [
                ['v' => $pair[0], 's' => self::S_LABEL],
                ['v' => $pair[1] ?? '', 's' => self::S_PLAIN],
            ];
        }
        if ($lines) {
            $lines[] = []; // blank separator row
        }

        if (empty($rows)) {
            $lines[] = [['v' => 'No data for this period', 's' => self::S_TEXT]];
            return $this->addRawSheet($name, $lines);
        }

        $columns = array_keys($rows[0]);

        $header = [];
        foreach ($columns as $col) {
            $header[] = ['v' => $this->humanise($col), 's' => self::S_HEADER];
        }
        $lines[]   = $header;
        $freezeRow = count($lines); // freeze everything down to the header row

        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $col) {
                $line[] = $this->autoCell($row[$col] ?? null);
            }
            $lines[] = $line;
        }

        if (!empty($meta['totals'])) {
            $totals = (array) $meta['totals'];
            $line   = [];
            foreach ($columns as $col) {
                $v    = $totals[$col] ?? '';
                $cell = $this->autoCell($v);
                $cell['s'] = ($cell['s'] === self::S_TEXT) ? self::S_TOTAL_TEXT : self::S_TOTAL_NUM;
                $line[] = $cell;
            }
            $lines[] = $line;
        }

        return $this->addRawSheet($name, $lines, $freezeRow);
    }

    /**
     * Add a sheet that stacks several titled query blocks one under another -
     * the same layout as the existing daily-bank CSV, but as real worksheets.
     *
     * @param array $blocks [['title' => string, 'rows' => array], ...]
     */
    public function addBlocksSheet(string $name, array $blocks, array $meta = []): self
    {
        $lines = [];

        if (!empty($meta['title'])) {
            $lines[] = [['v' => $meta['title'], 's' => self::S_TITLE]];
        }
        foreach ($meta['subtitle'] ?? [] as $pair) {
            $lines[] = [
                ['v' => $pair[0], 's' => self::S_LABEL],
                ['v' => $pair[1] ?? '', 's' => self::S_PLAIN],
            ];
        }
        $lines[] = [];

        foreach ($blocks as $block) {
            $lines[] = [['v' => $block['title'] ?? '', 's' => self::S_LABEL]];

            $rows = array_map(fn ($r) => (array) $r, $block['rows'] ?? []);
            if (empty($rows)) {
                $lines[] = [['v' => 'No data for this period', 's' => self::S_TEXT]];
            } else {
                $columns = array_keys($rows[0]);

                $header = [];
                foreach ($columns as $col) {
                    $header[] = ['v' => $this->humanise($col), 's' => self::S_HEADER];
                }
                $lines[] = $header;

                foreach ($rows as $row) {
                    $line = [];
                    foreach ($columns as $col) {
                        $line[] = $this->autoCell($row[$col] ?? null);
                    }
                    $lines[] = $line;
                }
            }
            $lines[] = []; // blank separator row
        }

        return $this->addRawSheet($name, $lines);
    }

    /**
     * Lowest-level entry point: add a sheet from a grid you built yourself.
     *
     * @param array $lines     List of rows; each row is a list of cells. A cell is
     *                         either a scalar or ['v' => mixed, 's' => style id].
     *                         An empty row array renders as a blank row.
     * @param int   $freezeRow Rows to freeze at the top (0 = no freeze pane).
     */
    public function addRawSheet(string $name, array $lines, int $freezeRow = 0): self
    {
        $normalised = [];
        $widths     = [];

        foreach ($lines as $line) {
            $cells = [];
            foreach (array_values((array) $line) as $i => $cell) {
                $cell    = is_array($cell) ? $cell : ['v' => $cell, 's' => self::S_PLAIN];
                $cells[] = $cell;

                $len = mb_strlen((string) ($cell['v'] ?? ''));
                $widths[$i] = max($widths[$i] ?? 9, min($len + 3, self::MAX_COL_WIDTH));
            }
            $normalised[] = $cells;
        }

        $this->sheets[] = [
            'name'   => $this->sheetName($name, count($this->sheets) + 1),
            'lines'  => $normalised,
            'freeze' => $freezeRow,
            'widths' => $widths,
        ];

        return $this;
    }

    public function sheetCount(): int
    {
        return count($this->sheets);
    }

    /**
     * Build and return the raw .xlsx bytes.
     */
    public function output(): string
    {
        if (empty($this->sheets)) {
            $this->addRawSheet('Sheet1', [[['v' => 'No data', 's' => self::S_TEXT]]]);
        }

        $parts = [
            '[Content_Types].xml'      => $this->contentTypesXml(),
            '_rels/.rels'              => $this->rootRelsXml(),
            'xl/workbook.xml'          => $this->workbookXml(),
            'xl/_rels/workbook.xml.rels' => $this->workbookRelsXml(),
            'xl/styles.xml'            => $this->stylesXml(),
        ];

        foreach ($this->sheets as $i => $sheet) {
            $parts['xl/worksheets/sheet' . ($i + 1) . '.xml'] = $this->sheetXml($sheet);
        }

        return $this->zip($parts);
    }

    // -----------------------------------------------------------------
    // Cell helpers
    // -----------------------------------------------------------------

    /**
     * Pick a cell type + style for a raw DB value.
     *
     * MySQL hands back DECIMAL/BIGINT as strings via PDO, so numeric-looking
     * strings are written as real numbers - otherwise Excel shows the green
     * "number stored as text" warning and SUM() over the column fails.
     *
     * Deliberately NOT converted to numbers (kept as text):
     *   - leading zeros ("007", "0091998...") - would be silently mangled
     *   - anything longer than 15 digits - beyond float precision, e.g. order refs
     */
    private function autoCell($value): array
    {
        if ($value === null || $value === '') {
            return ['v' => '', 's' => self::S_TEXT];
        }

        if (is_bool($value)) {
            return ['v' => $value ? 'TRUE' : 'FALSE', 's' => self::S_TEXT];
        }

        if (is_int($value)) {
            return ['v' => $value, 's' => self::S_INT, 'n' => true];
        }

        if (is_float($value)) {
            return ['v' => $value, 's' => self::S_DECIMAL, 'n' => true];
        }

        $str = (string) $value;

        if (is_numeric($str)
            && strlen(preg_replace('/\D/', '', $str)) <= 15
            && !preg_match('/^[+-]?0\d/', $str)
        ) {
            $isDecimal = (strpos($str, '.') !== false);
            return [
                'v' => $isDecimal ? (float) $str : (int) $str,
                's' => $isDecimal ? self::S_DECIMAL : self::S_INT,
                'n' => true,
            ];
        }

        return ['v' => $str, 's' => self::S_TEXT];
    }

    /** success_pct -> Success Pct */
    private function humanise(string $column): string
    {
        return ucwords(str_replace('_', ' ', $column));
    }

    /** Excel: max 31 chars, and [ ] : * ? / \ are illegal in a tab name. */
    private function sheetName(string $name, int $position): string
    {
        $name = trim(str_replace(['[', ']', ':', '*', '?', '/', '\\'], '-', $name));
        $name = mb_substr($name, 0, 31);

        return $name !== '' ? $name : 'Sheet' . $position;
    }

    /** 1 -> A, 27 -> AA */
    private static function colLetter(int $index): string
    {
        $letters = '';
        while ($index > 0) {
            $mod     = ($index - 1) % 26;
            $letters = chr(65 + $mod) . $letters;
            $index   = intdiv($index - $mod, 26);
        }

        return $letters;
    }

    /** XML-escape and drop control chars Excel refuses to load. */
    private static function esc($value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) $value);

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    // -----------------------------------------------------------------
    // XML parts
    // -----------------------------------------------------------------

    private function sheetXml(array $sheet): string
    {
        $lastCol = max(1, count($sheet['widths']));
        $lastRef = self::colLetter($lastCol) . max(1, count($sheet['lines']));

        $cols = '';
        foreach ($sheet['widths'] as $i => $width) {
            $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="'
                   . round($width, 2) . '" customWidth="1"/>';
        }

        $view = '<sheetView workbookViewId="0">';
        if ($sheet['freeze'] > 0) {
            $view .= '<pane ySplit="' . $sheet['freeze'] . '" topLeftCell="A' . ($sheet['freeze'] + 1)
                   . '" activePane="bottomLeft" state="frozen"/>';
        }
        $view .= '</sheetView>';

        $data = '';
        foreach ($sheet['lines'] as $r => $cells) {
            $rowNum = $r + 1;
            $data  .= '<row r="' . $rowNum . '">';
            foreach ($cells as $c => $cell) {
                $ref   = self::colLetter($c + 1) . $rowNum;
                $style = (int) ($cell['s'] ?? self::S_PLAIN);
                $val   = $cell['v'] ?? '';

                if (!empty($cell['n'])) {
                    $data .= '<c r="' . $ref . '" s="' . $style . '"><v>' . $val . '</v></c>';
                } elseif ($val === '' || $val === null) {
                    $data .= '<c r="' . $ref . '" s="' . $style . '"/>';
                } else {
                    // inlineStr: never evaluated as a formula (CWE-1236 safe).
                    $data .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr">'
                           . '<is><t xml:space="preserve">' . self::esc($val) . '</t></is></c>';
                }
            }
            $data .= '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<dimension ref="A1:' . $lastRef . '"/>'
            . '<sheetViews>' . $view . '</sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . ($cols !== '' ? '<cols>' . $cols . '</cols>' : '')
            . '<sheetData>' . $data . '</sheetData>'
            . '</worksheet>';
    }

    private function workbookXml(): string
    {
        $sheets = '';
        foreach ($this->sheets as $i => $sheet) {
            $sheets .= '<sheet name="' . self::esc($sheet['name']) . '" sheetId="' . ($i + 1)
                     . '" r:id="rId' . ($i + 1) . '"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheets . '</sheets>'
            . '</workbook>';
    }

    private function workbookRelsXml(): string
    {
        $base = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';

        $rels = '';
        foreach ($this->sheets as $i => $sheet) {
            $rels .= '<Relationship Id="rId' . ($i + 1) . '" Type="' . $base . 'worksheet"'
                   . ' Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
        }
        $rels .= '<Relationship Id="rId' . (count($this->sheets) + 1) . '" Type="' . $base . 'styles"'
               . ' Target="styles.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $rels . '</Relationships>';
    }

    private function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"'
            . ' Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function contentTypesXml(): string
    {
        $ns = 'application/vnd.openxmlformats-officedocument.spreadsheetml.';

        $overrides = '';
        foreach ($this->sheets as $i => $sheet) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml"'
                        . ' ContentType="' . $ns . 'worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="' . $ns . 'sheet.main+xml"/>'
            . $overrides
            . '<Override PartName="/xl/styles.xml" ContentType="' . $ns . 'styles+xml"/>'
            . '</Types>';
    }

    /**
     * Fonts / fills / borders / cellXfs. The cellXfs order here must match the
     * S_* constants at the top of this class.
     */
    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1">'
            . '<numFmt numFmtId="164" formatCode="#,##0.00"/>'
            . '</numFmts>'
            . '<fonts count="4">'
            . '<font><sz val="10"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="14"/><color rgb="FF1A5276"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="10"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="4">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF1A5276"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFEAF2F8"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border>'
            . '<left style="thin"><color rgb="FFBFBFBF"/></left>'
            . '<right style="thin"><color rgb="FFBFBFBF"/></right>'
            . '<top style="thin"><color rgb="FFBFBFBF"/></top>'
            . '<bottom style="thin"><color rgb="FFBFBFBF"/></bottom>'
            . '<diagonal/></border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="9">'
            // 0 S_PLAIN
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            // 1 S_TITLE
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            // 2 S_HEADER
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1"'
            . ' applyBorder="1" applyAlignment="1">'
            . '<alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            // 3 S_TEXT
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'
            // 4 S_INT
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1">'
            . '<alignment horizontal="right"/></xf>'
            // 5 S_DECIMAL
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'
            // 6 S_LABEL
            . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            // 7 S_TOTAL_TEXT
            . '<xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
            // 8 S_TOTAL_NUM
            . '<xf numFmtId="164" fontId="3" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1"'
            . ' applyFill="1" applyBorder="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    // -----------------------------------------------------------------
    // ZIP container (raw deflate via zlib - no ext-zip needed)
    // -----------------------------------------------------------------

    /**
     * @param array<string, string> $parts path inside the archive => file contents
     */
    private function zip(array $parts): string
    {
        [$dosTime, $dosDate] = $this->dosTimestamp();

        $local   = '';
        $central = '';
        $offset  = 0;

        foreach ($parts as $path => $contents) {
            $crc        = crc32($contents);
            $sizeRaw    = strlen($contents);
            $compressed = gzdeflate($contents, 9);
            $sizeZip    = strlen($compressed);

            $header = pack('vvvvvVVVvv',
                20,        // version needed to extract
                0,         // general purpose flags
                8,         // compression method: deflate
                $dosTime,
                $dosDate,
                $crc,
                $sizeZip,
                $sizeRaw,
                strlen($path),
                0          // extra field length
            );

            $local .= "PK\x03\x04" . $header . $path . $compressed;

            $central .= "PK\x01\x02" . pack('v', 20) . $header . pack('vvvVV',
                0,         // file comment length
                0,         // disk number start
                0,         // internal file attributes
                32,        // external file attributes (FILE_ATTRIBUTE_ARCHIVE)
                $offset    // offset of the local header
            ) . $path;

            $offset += 30 + strlen($path) + $sizeZip;
        }

        $count = count($parts);

        return $local . $central . "PK\x05\x06" . pack('vvvvVVv',
            0,
            0,
            $count,
            $count,
            strlen($central),
            $offset,
            0
        );
    }

    /** MS-DOS packed date/time pair used by the ZIP headers. */
    private function dosTimestamp(): array
    {
        $t = getdate();

        return [
            ($t['hours'] << 11) | ($t['minutes'] << 5) | ($t['seconds'] >> 1),
            (max($t['year'] - 1980, 0) << 9) | ($t['mon'] << 5) | $t['mday'],
        ];
    }
}

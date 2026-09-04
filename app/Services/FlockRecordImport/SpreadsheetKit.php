<?php

namespace App\Services\FlockRecordImport;

/**
 * Minimal XLSX/CSV reader-writer (no PhpSpreadsheet dependency).
 * Supports flat tabular sheets sufficient for flock record imports.
 */
class SpreadsheetKit
{
    /**
     * @return array<string, list<array<string, mixed>>> sheetName => rows of assoc arrays
     */
    public function readFile(string $absolutePath, string $extension): array
    {
        $extension = strtolower(ltrim($extension, '.'));

        return match ($extension) {
            'csv' => ['data' => $this->readCsv($absolutePath)],
            'xlsx' => $this->readXlsx($absolutePath),
            default => throw new \InvalidArgumentException("Unsupported spreadsheet type: {$extension}"),
        };
    }

    /**
     * @param  array<string, list<list<mixed>>>  $sheets  sheetName => rows of cell values (including header)
     */
    public function writeXlsx(string $absolutePath, array $sheets): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($absolutePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to create XLSX file');
        }

        $sheetNames = array_keys($sheets);
        $sharedStrings = [];
        $sharedIndex = [];

        $addShared = static function (string $value) use (&$sharedStrings, &$sharedIndex): int {
            if (! array_key_exists($value, $sharedIndex)) {
                $sharedIndex[$value] = count($sharedStrings);
                $sharedStrings[] = $value;
            }

            return $sharedIndex[$value];
        };

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml(count($sheetNames)));
        $zip->addFromString('_rels/.rels', $this->rootRelsXml());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml($sheetNames));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml(count($sheetNames)));
        $zip->addFromString('xl/styles.xml', $this->stylesXml());

        foreach (array_values($sheetNames) as $i => $name) {
            $rows = $sheets[$name] ?? [];
            $zip->addFromString(
                'xl/worksheets/sheet'.($i + 1).'.xml',
                $this->sheetXml($rows, $addShared)
            );
        }

        $zip->addFromString('xl/sharedStrings.xml', $this->sharedStringsXml($sharedStrings));
        $zip->close();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function readCsv(string $absolutePath): array
    {
        $handle = fopen($absolutePath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open CSV file');
        }

        $headers = null;
        $rows = [];
        while (($data = fgetcsv($handle)) !== false) {
            if ($data === [null] || $data === false) {
                continue;
            }
            if ($headers === null) {
                $headers = array_map(fn ($h) => $this->normalizeHeader((string) $h), $data);
                continue;
            }
            $assoc = [];
            $empty = true;
            foreach ($headers as $i => $header) {
                if ($header === '') {
                    continue;
                }
                $value = $data[$i] ?? null;
                if ($value !== null && trim((string) $value) !== '') {
                    $empty = false;
                }
                $assoc[$header] = is_string($value) ? trim($value) : $value;
            }
            if (! $empty) {
                $rows[] = $assoc;
            }
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function readXlsx(string $absolutePath): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($absolutePath) !== true) {
            throw new \RuntimeException('Unable to open XLSX file');
        }

        $sharedStrings = $this->parseSharedStrings($zip->getFromName('xl/sharedStrings.xml') ?: '');
        $workbook = $zip->getFromName('xl/workbook.xml') ?: '';
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels') ?: '';

        $sheetMap = $this->parseWorkbookSheets($workbook, $rels);
        $result = [];

        foreach ($sheetMap as $name => $path) {
            $xml = $zip->getFromName('xl/'.$path) ?: $zip->getFromName($path) ?: '';
            $matrix = $this->parseSheetMatrix($xml, $sharedStrings);
            if ($matrix === []) {
                $result[$name] = [];
                continue;
            }
            $headers = array_map(fn ($h) => $this->normalizeHeader((string) $h), $matrix[0]);
            $rows = [];
            for ($r = 1; $r < count($matrix); $r++) {
                $assoc = [];
                $empty = true;
                foreach ($headers as $c => $header) {
                    if ($header === '') {
                        continue;
                    }
                    $value = $matrix[$r][$c] ?? null;
                    if ($value !== null && trim((string) $value) !== '') {
                        $empty = false;
                    }
                    $assoc[$header] = is_string($value) ? trim($value) : $value;
                }
                if (! $empty) {
                    $rows[] = $assoc;
                }
            }
            $result[$name] = $rows;
        }

        $zip->close();

        return $result;
    }

    public function normalizeHeader(string $header): string
    {
        $header = strtolower(trim($header));
        $header = preg_replace('/[\s\-]+/', '_', $header) ?? $header;

        return trim($header, '_');
    }

    /**
     * @return list<string>
     */
    private function parseSharedStrings(string $xml): array
    {
        if ($xml === '') {
            return [];
        }
        $strings = [];
        $sx = @simplexml_load_string($xml);
        if (! $sx) {
            return [];
        }
        foreach ($sx->si as $si) {
            if (isset($si->t)) {
                $strings[] = (string) $si->t;
            } else {
                $text = '';
                foreach ($si->r as $run) {
                    $text .= (string) $run->t;
                }
                $strings[] = $text;
            }
        }

        return $strings;
    }

    /**
     * @return array<string, string> name => worksheet path
     */
    private function parseWorkbookSheets(string $workbookXml, string $relsXml): array
    {
        $rels = [];
        if ($relsXml !== '') {
            $rx = @simplexml_load_string($relsXml);
            if ($rx) {
                foreach ($rx->Relationship as $rel) {
                    $rels[(string) $rel['Id']] = ltrim((string) $rel['Target'], '/');
                }
            }
        }

        $map = [];
        $wx = @simplexml_load_string($workbookXml);
        if (! $wx) {
            return $map;
        }
        $wx->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $wx->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $sheets = $wx->sheets->sheet ?? [];
        $index = 1;
        foreach ($sheets as $sheet) {
            $name = (string) $sheet['name'];
            $rid = (string) ($sheet['r:id'] ?? $sheet->attributes('r', true)['id'] ?? '');
            $path = $rels[$rid] ?? ('worksheets/sheet'.$index.'.xml');
            if (! str_starts_with($path, 'worksheets/') && ! str_contains($path, '/')) {
                $path = 'worksheets/'.$path;
            }
            $map[$name] = $path;
            $index++;
        }

        return $map;
    }

    /**
     * @param  list<string>  $sharedStrings
     * @return list<list<mixed>>
     */
    private function parseSheetMatrix(string $xml, array $sharedStrings): array
    {
        if ($xml === '') {
            return [];
        }
        $sx = @simplexml_load_string($xml);
        if (! $sx) {
            return [];
        }

        $matrix = [];
        foreach ($sx->sheetData->row ?? [] as $row) {
            $rIndex = ((int) $row['r']) - 1;
            foreach ($row->c as $cell) {
                $ref = (string) $cell['r'];
                if (! preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) {
                    continue;
                }
                $cIndex = $this->columnIndex($m[1]);
                $type = (string) ($cell['t'] ?? '');
                $raw = isset($cell->v) ? (string) $cell->v : '';
                $value = $raw;
                if ($type === 's') {
                    $value = $sharedStrings[(int) $raw] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = (string) ($cell->is->t ?? '');
                } elseif ($raw !== '' && is_numeric($raw)) {
                    $value = str_contains($raw, '.') ? (float) $raw : (int) $raw;
                }
                $matrix[$rIndex][$cIndex] = $value;
            }
        }

        if ($matrix === []) {
            return [];
        }

        $maxRow = max(array_keys($matrix));
        $maxCol = 0;
        foreach ($matrix as $cols) {
            $maxCol = max($maxCol, max(array_keys($cols)));
        }

        $out = [];
        for ($r = 0; $r <= $maxRow; $r++) {
            $line = [];
            for ($c = 0; $c <= $maxCol; $c++) {
                $line[] = $matrix[$r][$c] ?? null;
            }
            $out[] = $line;
        }

        return $out;
    }

    private function columnIndex(string $letters): int
    {
        $index = 0;
        $letters = strtoupper($letters);
        for ($i = 0; $i < strlen($letters); $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }

        return $index - 1;
    }

    private function columnLetters(int $index): string
    {
        $index++;
        $letters = '';
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letters = chr(65 + $mod).$letters;
            $index = intdiv($index - 1, 26);
        }

        return $letters;
    }

    /**
     * @param  list<list<mixed>>  $rows
     * @param  callable(string):int  $addShared
     */
    private function sheetXml(array $rows, callable $addShared): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        foreach ($rows as $r => $cols) {
            $rowNum = $r + 1;
            $xml .= '<row r="'.$rowNum.'">';
            foreach ($cols as $c => $value) {
                $ref = $this->columnLetters($c).$rowNum;
                if ($value === null || $value === '') {
                    continue;
                }
                if (is_int($value) || is_float($value)) {
                    $xml .= '<c r="'.$ref.'"><v>'.$value.'</v></c>';
                } else {
                    $idx = $addShared((string) $value);
                    $xml .= '<c r="'.$ref.'" t="s"><v>'.$idx.'</v></c>';
                }
            }
            $xml .= '</row>';
        }

        $xml .= '</sheetData></worksheet>';

        return $xml;
    }

    /**
     * @param  list<string>  $strings
     */
    private function sharedStringsXml(array $strings): string
    {
        $count = count($strings);
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.$count.'" uniqueCount="'.$count.'">';
        foreach ($strings as $s) {
            $xml .= '<si><t>'.htmlspecialchars($s, ENT_XML1).'</t></si>';
        }
        $xml .= '</sst>';

        return $xml;
    }

    /**
     * @param  list<string>  $sheetNames
     */
    private function workbookXml(array $sheetNames): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        foreach ($sheetNames as $i => $name) {
            $xml .= '<sheet name="'.htmlspecialchars($name, ENT_XML1).'" sheetId="'.($i + 1).'" r:id="rId'.($i + 1).'"/>';
        }
        $xml .= '</sheets></workbook>';

        return $xml;
    }

    private function workbookRelsXml(int $sheetCount): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'<Relationship Id="rIdShared" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $xml .= '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
        }
        $xml .= '</Relationships>';

        return $xml;
    }

    private function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function contentTypesXml(int $sheetCount): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $xml .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $xml .= '</Types>';

        return $xml;
    }

    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            .'<borders count="1"><border/></borders>'
            .'<cellStyleXfs count="1"><xf/></cellStyleXfs>'
            .'<cellXfs count="1"><xf/></cellXfs>'
            .'</styleSheet>';
    }
}

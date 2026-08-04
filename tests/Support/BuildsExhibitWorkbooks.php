<?php

namespace Tests\Support;

/**
 * Builds a minimal xlsx mimicking a lessor's Exhibit A draft layout — a
 * title cell, the totals block, and a per-serial table mapped by header
 * text — so parser and endpoint tests can exercise real file uploads
 * without committing any signed documents to the repo.
 */
trait BuildsExhibitWorkbooks
{
    protected function writeExhibitWorkbook(string $path): void
    {
        $strings = [
            'Exhibit "A" Equipment Schedule No. 003, Lease No. 900123', // 0
            'Factor Category', 'LRF', 'Total Rent', 'Equip Cost', 'Soft Cost', 'Total Cost', // 1-6
            'Factor 2', // 7
            'Qty', 'Description', 'Serial Number', "New/\nUsed", 'Yearly Rental Per Unit', // 8-12
            "Equipment Commence-\nment Date", 'Per Piece Equipment Cost', 'Per Piece Soft Cost', 'Invoice Number', // 13-16
            'LAPTOP 14" X1', 'TESTSER001', 'New', 'INV001', // 17-20
            'TABLET 13" X2', 'TESTSER002', 'INV002', // 21-23
        ];

        $sst = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .implode('', array_map(fn ($s) => '<si><t>'.htmlspecialchars($s, ENT_XML1).'</t></si>', $strings))
            .'</sst>';

        $s = fn (int $i) => ['t' => 's', 'v' => $i];
        $n = fn ($v) => ['t' => null, 'v' => $v];

        $rows = [
            1 => ['A' => $s(0)],
            2 => ['A' => $s(1), 'B' => $s(2), 'D' => $s(3), 'E' => $s(4), 'F' => $s(5), 'G' => $s(6)],
            3 => ['A' => $s(7), 'B' => $n('0.25471'), 'D' => $n('8137.47'), 'E' => $n('29893.98'), 'F' => $n('2417.82'), 'G' => $n('32311.8')],
            4 => ['A' => $s(8), 'B' => $s(9), 'C' => $s(10), 'D' => $s(11), 'E' => $s(12), 'F' => $s(13), 'G' => $s(14), 'H' => $s(15), 'I' => $s(16), 'J' => $s(6)],
            5 => ['A' => $n('1'), 'B' => $s(17), 'C' => $s(18), 'D' => $s(19), 'E' => $n('906.87'), 'F' => $n('46139'), 'G' => $n('3061.22'), 'H' => $n('396.42'), 'I' => $s(20), 'J' => $n('3457.64')],
            6 => ['A' => $n('1'), 'B' => $s(21), 'C' => $s(22), 'D' => $s(19), 'E' => $n('327.69'), 'F' => $n('46191'), 'G' => $n('2629'), 'H' => $n('349.5'), 'I' => $s(23), 'J' => $n('2978.5')],
        ];

        $sheetRows = '';
        foreach ($rows as $number => $cells) {
            $sheetRows .= '<row r="'.$number.'">';
            foreach ($cells as $column => $cell) {
                $sheetRows .= '<c r="'.$column.$number.'"'.($cell['t'] ? ' t="'.$cell['t'].'"' : '').'><v>'.$cell['v'].'</v></c>';
            }
            $sheetRows .= '</row>';
        }

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'.$sheetRows.'</sheetData></worksheet>';

        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->addFromString('xl/sharedStrings.xml', $sst);
        $zip->close();
    }
}

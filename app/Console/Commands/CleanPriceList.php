<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Rewrites a vendor workbook keeping only the cells that carry data.
 *
 * Reseller price lists arrive enormous for their content — CDW's July 2026
 * Apple catalog was 5.9MB of stored XML wrapping 39 rows, because someone
 * formatted whole columns and Excel then materialised all 1,048,576 of them.
 *
 * The importer itself doesn't care: the reader stops at the last populated
 * row either way. Upload limits do care, and a 2MB PHP default rejects the
 * file long before it reaches any of this. Running a list through here first
 * turns megabytes back into kilobytes without touching the data.
 */
class CleanPriceList extends Command
{
    protected $signature = 'catalog:clean-price-list
        {file : The bloated workbook (.xlsx)}
        {output? : Where to write the cleaned copy (defaults to "<name> (clean).xlsx" alongside it)}';

    protected $description = 'Strip empty formatted rows and columns out of a vendor price list workbook.';

    public function handle(): int
    {
        $path = $this->argument('file');

        if (! is_readable($path)) {
            $this->error("Cannot read file: {$path}");

            return self::FAILURE;
        }

        $output = $this->argument('output') ?: sprintf(
            '%s/%s (clean).xlsx',
            dirname($path),
            pathinfo($path, PATHINFO_FILENAME)
        );

        $reader = new XlsxReader;
        $reader->open($path);

        $writer = new XlsxWriter;
        $writer->openToFile($output);

        $kept = 0;
        $dropped = 0;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $cells = array_map(
                    fn ($cell) => is_scalar($cell) ? trim((string) $cell) : '',
                    $row->toArray()
                );

                if (implode('', $cells) === '') {
                    $dropped++;

                    continue;
                }

                // Trailing empties are formatting spill too, and they widen
                // every row in the rewritten file if left on.
                while ($cells !== [] && end($cells) === '') {
                    array_pop($cells);
                }

                $writer->addRow(Row::fromValues($cells));
                $kept++;
            }

            // Price lists are single-sheet; a second tab is notes, not data.
            break;
        }

        $reader->close();
        $writer->close();

        $this->info(sprintf(
            'Wrote %s — %d rows kept%s, %s → %s.',
            basename($output),
            $kept,
            $dropped ? ", {$dropped} empty rows dropped" : '',
            $this->humanBytes(filesize($path)),
            $this->humanBytes(filesize($output))
        ));

        return self::SUCCESS;
    }

    private function humanBytes(int $bytes): string
    {
        foreach (['B', 'KB', 'MB'] as $unit) {
            if ($bytes < 1024 || $unit === 'MB') {
                return round($bytes, 1).$unit;
            }
            $bytes /= 1024;
        }

        return $bytes.'B';
    }
}

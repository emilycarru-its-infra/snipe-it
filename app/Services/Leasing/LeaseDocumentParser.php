<?php

namespace App\Services\Leasing;

use Carbon\Carbon;
use Smalot\PdfParser\Parser;

/**
 * Classifies and extracts the lessor document types that arrive over email
 * during a lease schedule's life:
 *
 *   - schedule_agreement        — a signed schedule agreement opening a new
 *                                 numbered schedule (term, cost cap, factors,
 *                                 purchase option).
 *   - certificate_of_acceptance — the finalization document: signed term
 *                                 dates, yearly rental, stip loss, and the
 *                                 final Exhibit A rows (serial, description,
 *                                 per-unit rental, commencement).
 *   - exhibit_a_draft           — the lessor's reconciliation spreadsheet:
 *                                 per-serial equipment cost, soft cost,
 *                                 invoice numbers and schedule totals.
 *
 * PDFs are read with smalot/pdfparser; the xlsx is read directly from its
 * XML parts so no spreadsheet dependency is needed. All extraction works on
 * whitespace-normalized text because the PDF extractor scatters tabs inside
 * tokens (e.g. "6/18/2\t026").
 */
class LeaseDocumentParser
{
    public const TYPE_SCHEDULE_AGREEMENT = 'schedule_agreement';

    public const TYPE_CERTIFICATE_OF_ACCEPTANCE = 'certificate_of_acceptance';

    public const TYPE_EXHIBIT_A_DRAFT = 'exhibit_a_draft';

    /**
     * Parse an absolute path to a dropped file. Returns a normalized array
     * with a `type` key, or ['type' => null, 'error' => ...] when the file
     * isn't recognizable as any of the three document kinds.
     */
    public function parse(string $absolutePath, ?string $originalName = null): array
    {
        $name = $originalName ?? basename($absolutePath);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (in_array($extension, ['xlsx', 'xlsm'], true)) {
            return $this->parseExhibitWorkbook($absolutePath);
        }

        if ($extension !== 'pdf') {
            return ['type' => null, 'error' => trans('admin/lease-intake/general.unsupported_file')];
        }

        try {
            $text = (new Parser)->parseFile($absolutePath)->getText();
        } catch (\Throwable $e) {
            return ['type' => null, 'error' => trans('admin/lease-intake/general.unreadable_pdf')];
        }

        return $this->parsePdfText($text);
    }

    /**
     * Classify and extract from already-extracted PDF text. Public so tests
     * can exercise the extraction without round-tripping a real PDF.
     */
    public function parsePdfText(string $rawText): array
    {
        $text = $this->normalize($rawText);

        if (stripos($text, 'CERTIFICATE OF ACCEPTANCE') !== false) {
            return $this->parseCertificateOfAcceptance($text);
        }

        if (stripos($text, 'SMARTTRACK SCHEDULE NO') !== false) {
            return $this->parseScheduleAgreement($text);
        }

        return ['type' => null, 'error' => trans('admin/lease-intake/general.unrecognized_document')];
    }

    /**
     * Strip the tab noise the PDF extractor injects mid-token, then collapse
     * runs of spaces. Newlines are preserved — the Exhibit A row parser is
     * line-oriented.
     */
    private function normalize(string $text): string
    {
        $text = str_replace("\t", '', $text);

        return preg_replace('/ {2,}/', ' ', $text);
    }

    // ─── Certificate of Acceptance ──────────────────────────────────────

    private function parseCertificateOfAcceptance(string $text): array
    {
        $flat = preg_replace('/\s+/', ' ', $text);

        $scheduleNumber = $this->match('/Equipment Schedule Number (\d{1,3})/i', $flat);
        $leaseNumber = $this->match('/Lease\s?Number (\d{4,})/i', $flat)
            ?? $this->match('/Lease No\.?\s?(\d{4,})/i', $flat);

        $termStart = $this->date($this->match('/FIRST DAY OF INITIAL TERM (\d{1,2}\/\d{1,2}\/\d{4})/i', $flat));
        $termEnd = $this->date($this->match('/LEASE EXPIRATION DATE (\d{1,2}\/\d{1,2}\/\d{4})/i', $flat));

        return [
            'type' => self::TYPE_CERTIFICATE_OF_ACCEPTANCE,
            'lessor' => $this->match('/between (.+?) \(the "Lessor"\)/', $flat),
            'lease_number' => $leaseNumber,
            'schedule_ref' => $this->scheduleRef($leaseNumber, $scheduleNumber),
            'dated_as_of' => $this->date($this->match('/dated as of ([A-Z][a-z]+ \d{1,2}, \d{4})/', $flat)),
            'term_start' => $termStart,
            'term_end' => $termEnd,
            'term_months' => $this->monthsBetween($termStart, $termEnd),
            'yearly_rental' => $this->money($this->match('/YEARLY RENTAL AMOUNT \$([\d,]+\.\d{2})/i', $flat)),
            'stip_loss_value' => $this->money($this->match('/STIP LOSS BASE VALUE \$([\d,]+\.\d{2})/i', $flat)),
            'equipment_location' => $this->match('/EQUIPMENT LOCATION (.+?) FIRST DAY/i', $flat),
            'lines' => $this->exhibitLinesFromText($text),
        ];
    }

    /**
     * Pull "qty description serial New/Used rental commencement" rows out of
     * the embedded Exhibit A table. The machine-type column renders on
     * separate lines in extracted text, so a row is: qty, then description,
     * then a serial-looking token, condition, money, date.
     */
    private function exhibitLinesFromText(string $text): array
    {
        $lines = [];

        foreach (preg_split('/\R/', $text) as $line) {
            // Serial is either a real serial or N\/A — soft-cost lines like
            // rack kits are financed on the schedule without a serial and
            // must still count toward the rental total.
            if (preg_match(
                '/^(\d+) (.+?) ([A-Z0-9]{8,18}|N\/A) (New|Used) ([\d,]+\.\d{2}) (\d{1,2}\/\d{1,2}\/\d{4})\s*$/',
                trim($line),
                $m
            )) {
                $lines[] = [
                    'qty' => (int) $m[1],
                    'description' => trim($m[2]),
                    'serial' => $m[3] === 'N/A' ? null : strtoupper($m[3]),
                    'condition' => $m[4],
                    'yearly_rental' => $this->money($m[5]),
                    'commencement' => $this->date($m[6]),
                ];
            }
        }

        return $lines;
    }

    // ─── Schedule agreement ─────────────────────────────────────────────

    private function parseScheduleAgreement(string $text): array
    {
        $flat = preg_replace('/\s+/', ' ', $text);

        $scheduleNumber = $this->match('/SMARTTRACK SCHEDULE NO\.? (\d{1,3})/i', $flat);
        $leaseNumber = $this->match('/Lease Agreement No\.? (\d{4,})/i', $flat);

        $termMonths = $this->match('/Initial Term is (\d{1,3}) months/i', $flat);
        $termStart = $this->date($this->match('/starting on ([A-Z][a-z]+ \d{1,2}, \d{4})/', $flat));
        $termEnd = $this->date($this->match('/expiring on ([A-Z][a-z]+ \d{1,2}, \d{4})/', $flat));

        // A $1.00 purchase option is what distinguishes a lease-to-own
        // schedule from a lease-to-return one.
        $purchaseOption = (bool) preg_match('/buy the Equipment for \$1\.00/i', $flat);

        return [
            'type' => self::TYPE_SCHEDULE_AGREEMENT,
            'lessor' => $this->titleCase($this->match('/LESSOR:\s*(?:LESSEE:\s*)?(.+?(?:LTD|INC|CORP|LLC)\.?)\s/i', $flat)),
            'lease_number' => $leaseNumber,
            'schedule_ref' => $this->scheduleRef($leaseNumber, $scheduleNumber),
            'dated_as_of' => $this->date($this->match('/DATED (?:AS OF )?([A-Z]+ \d{1,2}, \d{4})/i', $flat)),
            'term_start' => $termStart,
            'term_end' => $termEnd,
            'term_months' => $termMonths ? (int) $termMonths : $this->monthsBetween($termStart, $termEnd),
            'cost_cap' => $this->money($this->match('/will not exceed:? CAD\s?([\d,]+\.\d{2})/i', $flat)),
            'soft_cost_factor' => $this->factor($this->match('/Soft Cost Factor is (\.\d+)/i', $flat)),
            'lease_rate_factor' => $this->factor($this->match('/(\.\d{4,}) times Unit cost/i', $flat)),
            'purchase_option' => $purchaseOption,
            'lease_type' => $purchaseOption ? 'Lease to Own' : 'Lease to Return',
        ];
    }

    // ─── Exhibit A draft workbook ───────────────────────────────────────

    /**
     * Read an Exhibit A draft xlsx straight from its XML parts. The sheet
     * carries a totals block (Total Rent / Equip Cost / Soft Cost / Total
     * Cost) and a per-serial table whose columns are mapped by header text,
     * so column reordering between drafts doesn't break extraction.
     */
    public function parseExhibitWorkbook(string $absolutePath): array
    {
        $rows = $this->workbookRows($absolutePath);

        if ($rows === null) {
            return ['type' => null, 'error' => trans('admin/lease-intake/general.unreadable_workbook')];
        }

        $title = collect($rows)->flatten()->first(fn ($v) => is_string($v) && stripos($v, 'Schedule No') !== false) ?? '';
        $scheduleNumber = $this->match('/Schedule No\.? (\d{1,3})/i', $title);
        $leaseNumber = $this->match('/Lease No\.? (\d{4,})/i', $title);

        return [
            'type' => self::TYPE_EXHIBIT_A_DRAFT,
            'lease_number' => $leaseNumber,
            'schedule_ref' => $this->scheduleRef($leaseNumber, $scheduleNumber),
            'totals' => $this->workbookTotals($rows),
            'lines' => $this->workbookLines($rows),
        ];
    }

    /** All rows of the first worksheet as arrays of scalar cell values. */
    private function workbookRows(string $absolutePath): ?array
    {
        $zip = new \ZipArchive;

        if ($zip->open($absolutePath) !== true) {
            return null;
        }

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        $zip->close();

        if ($sheetXml === false) {
            return null;
        }

        $shared = [];
        if ($sharedXml !== false) {
            $sst = @simplexml_load_string($sharedXml);
            if ($sst !== false) {
                foreach ($sst->si as $si) {
                    // A shared string is either a single <t> or rich-text runs.
                    $shared[] = isset($si->t) ? (string) $si->t : implode('', array_map(fn ($r) => (string) $r->t, iterator_to_array($si->r, false)));
                }
            }
        }

        $sheet = @simplexml_load_string($sheetXml);
        if ($sheet === false) {
            return null;
        }

        $rows = [];
        foreach ($sheet->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                $index = $this->columnIndex(preg_replace('/\d+/', '', $ref));
                $value = isset($c->v) ? (string) $c->v : '';
                if ((string) $c['t'] === 's' && $value !== '') {
                    $value = $shared[(int) $value] ?? '';
                }
                $cells[$index] = trim($value);
            }
            if ($cells !== []) {
                // Re-index densely so header/value positions line up.
                $padded = array_fill(0, max(array_keys($cells)) + 1, '');
                foreach ($cells as $i => $v) {
                    $padded[$i] = $v;
                }
                $rows[] = $padded;
            }
        }

        return $rows;
    }

    private function columnIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split(strtoupper($letters)) as $ch) {
            $index = $index * 26 + (ord($ch) - 64);
        }

        return $index - 1;
    }

    /** The Total Rent / Equip Cost / Soft Cost / Total Cost header block. */
    private function workbookTotals(array $rows): array
    {
        $map = [
            'Total Rent' => 'total_rent',
            'Equip Cost' => 'equipment_cost',
            'Soft Cost' => 'soft_cost',
            'Total Cost' => 'total_cost',
            'Total Financed Interim Rent' => 'financed_interim_rent',
            'LRF' => 'lease_rate_factor',
        ];

        foreach ($rows as $i => $row) {
            if (! in_array('Total Rent', $row, true)) {
                continue;
            }
            $values = $rows[$i + 1] ?? [];
            $totals = [];
            foreach ($row as $col => $header) {
                $key = $map[trim((string) $header)] ?? null;
                if ($key && isset($values[$col]) && is_numeric($values[$col])) {
                    $totals[$key] = round((float) $values[$col], $key === 'lease_rate_factor' ? 6 : 2);
                }
            }

            return $totals;
        }

        return [];
    }

    /** Per-serial rows, columns resolved by header text. */
    private function workbookLines(array $rows): array
    {
        $headerMap = [
            'Qty' => 'qty',
            'Description' => 'description',
            'Serial Number' => 'serial',
            'New/ Used' => 'condition',
            'Yearly Rental Per Unit' => 'yearly_rental',
            'Equipment Commence- ment Date' => 'commencement',
            'Per Piece Equipment Cost' => 'equipment_cost',
            'Per Piece Soft Cost' => 'soft_cost',
            'Invoice Number' => 'invoice_numbers',
            'Total Cost' => 'total_cost',
            'Base Monthly Rental Per Unit' => 'monthly_rental',
            'Equipment Location' => 'location',
        ];

        $columns = null;
        $lines = [];

        foreach ($rows as $row) {
            if ($columns === null) {
                if (in_array('Serial Number', array_map(fn ($v) => $this->squashHeader($v), $row), true)) {
                    $columns = [];
                    foreach ($row as $col => $header) {
                        $key = $headerMap[$this->squashHeader($header)] ?? null;
                        if ($key) {
                            $columns[$key] = $col;
                        }
                    }
                }

                continue;
            }

            $serial = strtoupper(trim((string) ($row[$columns['serial'] ?? -1] ?? '')));
            if ($serial === 'N/A') {
                // Unserialized financed line (soft costs, rack kits) — keep
                // the money, drop the serial.
                $serial = null;
            } elseif ($serial === '' || ! preg_match('/^[A-Z0-9]{6,18}$/', $serial)) {
                continue;
            }

            $line = ['serial' => $serial];
            foreach (['qty', 'description', 'condition', 'invoice_numbers', 'location'] as $key) {
                if (isset($columns[$key])) {
                    $line[$key] = trim((string) ($row[$columns[$key]] ?? '')) ?: null;
                }
            }
            $line['qty'] = isset($line['qty']) ? (int) $line['qty'] : 1;
            foreach (['yearly_rental', 'equipment_cost', 'soft_cost', 'total_cost', 'monthly_rental'] as $key) {
                $raw = $row[$columns[$key] ?? -1] ?? '';
                $line[$key] = is_numeric($raw) ? round((float) $raw, 2) : null;
            }
            $commencement = $row[$columns['commencement'] ?? -1] ?? '';
            $line['commencement'] = is_numeric($commencement) ? $this->excelDate((float) $commencement) : null;

            $lines[] = $line;
        }

        return $lines;
    }

    /** Collapse newlines/multi-space inside a header cell for matching. */
    private function squashHeader(?string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', (string) $value));
    }

    // ─── Small conversions ──────────────────────────────────────────────

    private function match(string $pattern, string $subject): ?string
    {
        return preg_match($pattern, $subject, $m) ? trim($m[1]) : null;
    }

    /**
     * "ACME LEASING CANADA LTD." → "Acme Leasing Canada Ltd.", keeping
     * short tokens ("CSI", "IBM", "HP") as the acronyms they are. Company
     * suffixes are the exception: they read as words, not acronyms.
     */
    private function titleCase(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $suffixes = ['LTD', 'INC', 'CORP', 'LLC', 'CO'];

        return implode(' ', array_map(
            function ($word) use ($suffixes) {
                $bare = rtrim($word, '.');
                if (ctype_upper($bare) && strlen($bare) <= 3 && ! in_array($bare, $suffixes, true)) {
                    return $word;
                }

                return ucwords(strtolower($word));
            },
            explode(' ', $value)
        ));
    }

    private function money(?string $value): ?float
    {
        return $value === null ? null : (float) str_replace(',', '', $value);
    }

    private function factor(?string $value): ?float
    {
        return $value === null ? null : (float) ('0'.$value);
    }

    private function date(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function excelDate(float $serial): ?string
    {
        if ($serial < 1) {
            return null;
        }

        return gmdate('Y-m-d', (int) round(($serial - 25569) * 86400));
    }

    private function monthsBetween(?string $start, ?string $end): ?int
    {
        if (! $start || ! $end) {
            return null;
        }

        // A lease term like 07/01/2026 → 06/30/2030 is 48 whole months;
        // count from the day after the end date so the arithmetic is exact.
        return (int) round(Carbon::parse($start)->diffInMonths(Carbon::parse($end)->addDay()));
    }

    private function scheduleRef(?string $leaseNumber, ?string $scheduleNumber): ?string
    {
        if (! $leaseNumber || ! $scheduleNumber) {
            return null;
        }

        return $leaseNumber.'-'.str_pad($scheduleNumber, 3, '0', STR_PAD_LEFT);
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;

/**
 * Extracts serial numbers from an Annexure A PDF (the lessor's per-asset
 * appendix that lists every device on a schedule). Lessors email these
 * as PDFs, so the parser walks the extracted text and matches anything
 * that looks like a hardware serial.
 *
 * The matcher is intentionally permissive — Annexure layouts differ
 * across CSI Leasing, CCA Financial, and CCA Financial. Heuristic:
 *   - All-uppercase tokens 8–18 chars long
 *   - Must contain at least one digit (rules out plain English words)
 *   - Must contain at least one letter (rules out pure numeric IDs like
 *     line numbers and SKUs)
 *   - Stripped of leading/trailing punctuation
 *
 * Returns serials uppercased and deduplicated, preserving first-seen
 * order so the diff view reads the same way as the PDF.
 */
class AnnexureParser
{
    /**
     * Tokens we know aren't serials even though they pass the regex
     * (column headings, lessor markers, asset-tag prefixes that show
     * up as standalone strings in extracted text).
     */
    private const BLOCKLIST = [
        'ANNEXURE',
        'SCHEDULE',
        'EXHIBIT',
        'SERIAL',
        'SERIALNUMBER',
        'INVOICE',
        'CUSTOMER',
        'LESSOR',
        'LESSEE',
        'SUPPLIER',
        'ACCOUNT',
        'P0025',
        'PMCN',
        'PMVM',
        'PMZP',
        'ECI',
        'CSI',
        'EMILYCARR',
    ];

    /**
     * Parse the PDF at the given storage-relative path and return the
     * extracted serials. Returns an empty array on parse failure (the
     * caller treats that as "diff against nothing").
     */
    public function serialsFromPdf(string $relativePath): array
    {
        if (! Storage::exists($relativePath)) {
            return [];
        }

        try {
            $parser = new Parser;
            $pdf = $parser->parseContent(Storage::get($relativePath));
            $text = $pdf->getText();
        } catch (\Throwable $e) {
            Log::warning("AnnexureParser failed to parse {$relativePath}: ".$e->getMessage());

            return [];
        }

        return $this->extractSerials($text);
    }

    /**
     * Pull serial-like tokens from raw extracted text. Exposed for tests
     * that don't want to round-trip through a real PDF.
     */
    public function extractSerials(string $text): array
    {
        // Snipe-IT serials and Apple/CDW lessor serials run 8-18 chars
        // and are uppercase alphanumeric. The regex deliberately doesn't
        // allow dashes — those show up in schedule references, not
        // hardware serials.
        preg_match_all('/\b[A-Z0-9]{8,18}\b/', strtoupper($text), $matches);

        $serials = [];
        $seen = [];

        foreach ($matches[0] ?? [] as $token) {
            if (! $this->looksLikeSerial($token)) {
                continue;
            }
            if (isset($seen[$token])) {
                continue;
            }
            $seen[$token] = true;
            $serials[] = $token;
        }

        return $serials;
    }

    private function looksLikeSerial(string $token): bool
    {
        if (in_array($token, self::BLOCKLIST, true)) {
            return false;
        }

        // Must mix letters and digits — a serial is neither a pure
        // word nor a pure number.
        $hasDigit = preg_match('/\d/', $token) === 1;
        $hasLetter = preg_match('/[A-Z]/', $token) === 1;

        if (! ($hasDigit && $hasLetter)) {
            return false;
        }

        // Block-list prefixes catch families we know are noise (CDW
        // order numbers like PMCN361, Snipe asset tags like P0025419).
        foreach (self::BLOCKLIST as $prefix) {
            if (str_starts_with($token, $prefix)) {
                return false;
            }
        }

        return true;
    }
}

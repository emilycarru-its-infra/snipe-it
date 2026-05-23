<?php

namespace Tests\Feature\LeaseSchedules;

use App\Services\AnnexureParser;
use Tests\TestCase;

class AnnexureParserTest extends TestCase
{
    public function test_extracts_uppercase_alphanumeric_serials_from_raw_text()
    {
        $parser = new AnnexureParser;

        $text = "Annexure A — Schedule 301452-007\n".
            "Serial         Model           Asset Tag\n".
            "C02C80E0M0XV   iMac Pro        L002497\n".
            "C02C80P3M0XV   iMac Pro        L002495\n".
            "JX5J71KQ9W     iPad Pro        L003120\n";

        $this->assertEquals(
            ['C02C80E0M0XV', 'C02C80P3M0XV', 'JX5J71KQ9W'],
            $parser->extractSerials($text)
        );
    }

    public function test_blocks_column_headings_and_known_prefixes()
    {
        $parser = new AnnexureParser;

        $text = 'ANNEXURE INVOICE LESSOR '.
            'P0025395 PMCN361 ECI20240801 CSI '.
            'C02C80E0M0XV';

        $this->assertEquals(['C02C80E0M0XV'], $parser->extractSerials($text));
    }

    public function test_skips_pure_word_or_pure_numeric_tokens()
    {
        $parser = new AnnexureParser;

        // Pure words and pure numbers should never count as serials —
        // serials always mix letters and digits.
        $text = 'PURCHASE 12345678 0123456789 LAPTOP TUESDAY ABCD1234XYZ';

        $this->assertEquals(['ABCD1234XYZ'], $parser->extractSerials($text));
    }

    public function test_deduplicates_repeated_serials_preserving_order()
    {
        $parser = new AnnexureParser;

        $text = 'C02C80E0M0XV JX5J71KQ9W C02C80E0M0XV JX5J71KQ9W';

        $this->assertEquals(['C02C80E0M0XV', 'JX5J71KQ9W'], $parser->extractSerials($text));
    }

    public function test_returns_empty_array_for_missing_pdf_file()
    {
        $parser = new AnnexureParser;
        $this->assertEquals([], $parser->serialsFromPdf('private_uploads/missing.pdf'));
    }
}

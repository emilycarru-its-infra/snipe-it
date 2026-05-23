<?php

namespace Tests\Feature\LeaseSchedules;

use App\Services\AnnexureParser;
use Tests\TestCase;

class AnnexureParserTest extends TestCase
{
    public function test_extracts_uppercase_alphanumeric_serials_from_raw_text()
    {
        $parser = new AnnexureParser;

        $text = "Annexure A — Schedule 100000-007\n".
            "Serial         Model           Asset Tag\n".
            "TESTSN000001   iMac Pro        TESTL01\n".
            "TESTSN000002   iMac Pro        TESTL02\n".
            "TESTSN0003     iPad Pro        TESTL03\n";

        $this->assertEquals(
            ['TESTSN000001', 'TESTSN000002', 'TESTSN0003'],
            $parser->extractSerials($text)
        );
    }

    public function test_blocks_column_headings_and_known_prefixes()
    {
        $parser = new AnnexureParser;

        $text = 'ANNEXURE INVOICE LESSOR '.
            'P0025000 TESTPM4 ECI20240807 CSI '.
            'TESTSN000001';

        $this->assertEquals(['TESTSN000001'], $parser->extractSerials($text));
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

        $text = 'TESTSN000001 TESTSN0003 TESTSN000001 TESTSN0003';

        $this->assertEquals(['TESTSN000001', 'TESTSN0003'], $parser->extractSerials($text));
    }

    public function test_returns_empty_array_for_missing_pdf_file()
    {
        $parser = new AnnexureParser;
        $this->assertEquals([], $parser->serialsFromPdf('private_uploads/missing.pdf'));
    }
}

<?php

namespace Tests\Unit;

use App\Services\Leasing\LeaseDocumentParser;
use Tests\Support\BuildsExhibitWorkbooks;
use Tests\TestCase;

/**
 * Extraction from raw document text, mirroring the shapes the lessor's PDF
 * extractor produces — including the stray tabs it injects mid-token. Uses
 * synthetic fixture text; no signed documents are committed to the repo.
 */
class LeaseDocumentParserTest extends TestCase
{
    use BuildsExhibitWorkbooks;

    private function parser(): LeaseDocumentParser
    {
        return new LeaseDocumentParser;
    }

    public function test_classifies_and_extracts_a_certificate_of_acceptance()
    {
        $text = implode("\n", [
            'CERTIFICATE OF ACCEPTANCE',
            'This Certificate is executed pursuant to Equipment Schedule Number 003	, dated as of April 09, 2026 to Master Lease',
            'Number 900123 dated June 11, 2025, between Example Leasing Canada Ltd.',
            ' (the "Lessor") and Example University (the "Lessee").',
            'EQUIPMENT LOCATION',
            '123 EXAMPLE ST',
            'VANCOUVER, BC V0V 0V0',
            'FIRST DAY OF INITIAL TERM 07/01/2026',
            'LEASE EXPIRATION DATE 	06/30/2030',
            'YEARLY RENTAL AMOUNT $1,234.56 CAD',
            'STIP LOSS BASE VALUE 	$5,678.90 CAD',
            'Qty Description Serial Number New/',
            '1 LAPTOP 14" X1 TESTSER001 New 906.87 4/27/2026',
            '1 TABLET 13" X2 TESTSER002 New 327.69 6/18/2	026',
        ]);

        $parsed = $this->parser()->parsePdfText($text);

        $this->assertSame(LeaseDocumentParser::TYPE_CERTIFICATE_OF_ACCEPTANCE, $parsed['type']);
        $this->assertSame('900123', $parsed['lease_number']);
        $this->assertSame('900123-003', $parsed['schedule_ref']);
        $this->assertSame('2026-04-09', $parsed['dated_as_of']);
        $this->assertSame('2026-07-01', $parsed['term_start']);
        $this->assertSame('2030-06-30', $parsed['term_end']);
        $this->assertSame(48, $parsed['term_months']);
        $this->assertSame(1234.56, $parsed['yearly_rental']);
        $this->assertSame(5678.9, $parsed['stip_loss_value']);
        $this->assertSame('Example Leasing Canada Ltd.', $parsed['lessor']);

        $this->assertCount(2, $parsed['lines']);
        $this->assertSame('TESTSER001', $parsed['lines'][0]['serial']);
        $this->assertSame('LAPTOP 14" X1', $parsed['lines'][0]['description']);
        $this->assertSame(906.87, $parsed['lines'][0]['yearly_rental']);
        $this->assertSame('2026-04-27', $parsed['lines'][0]['commencement']);
        // The second line's commencement date carries the extractor's
        // mid-token tab and must still parse.
        $this->assertSame('2026-06-18', $parsed['lines'][1]['commencement']);
    }

    public function test_classifies_and_extracts_a_schedule_agreement()
    {
        $text = implode("\n", [
            'SMARTTRACK SCHEDULE NO. 9 DATED JULY 22, 2026',
            'LESSOR: LESSEE:',
            'EXAMPLE LEASING CANADA LTD. Unit #4',
            'all of the terms and conditions of the Master Lease Agreement No. 900123 dated June 11, 2025, are hereby',
            '3. Total Cost of the Schedule: The Total Cost will not exceed: CAD250,000.00.',
            '4. Initial Term: The Initial Term is 48 months, starting on October 1, 2026, and expiring on September 30, 2030.',
            '6. Software License Fees and Other Costs: The Soft Cost Factor is .27639.',
            '.24658 times Unit cost',
        ]);

        $parsed = $this->parser()->parsePdfText($text);

        $this->assertSame(LeaseDocumentParser::TYPE_SCHEDULE_AGREEMENT, $parsed['type']);
        $this->assertSame('900123-009', $parsed['schedule_ref']);
        $this->assertSame('2026-07-22', $parsed['dated_as_of']);
        $this->assertSame('2026-10-01', $parsed['term_start']);
        $this->assertSame('2030-09-30', $parsed['term_end']);
        $this->assertSame(48, $parsed['term_months']);
        $this->assertSame(250000.0, $parsed['cost_cap']);
        $this->assertSame(0.27639, $parsed['soft_cost_factor']);
        $this->assertSame(0.24658, $parsed['lease_rate_factor']);
        $this->assertFalse($parsed['purchase_option']);
        $this->assertSame('Lease to Return', $parsed['lease_type']);
        $this->assertSame('Example Leasing Canada Ltd.', $parsed['lessor']);
    }

    public function test_a_one_dollar_purchase_option_means_lease_to_own()
    {
        $text = implode("\n", [
            'SMARTTRACK SCHEDULE NO. 10 DATED AS OF JULY 27, 2026',
            'Master Lease Agreement No. 900123 dated June 11, 2025',
            'Initial Term: The Initial Term is 60 months, starting on October 1, 2026, and expiring on September 30, 2031.',
            'Purchase Option: Provided Lessee is not then in default under the Lease, Lessee may buy the Equipment for $1.00',
            'on the last day of the Initial Term.',
        ]);

        $parsed = $this->parser()->parsePdfText($text);

        $this->assertSame('900123-010', $parsed['schedule_ref']);
        $this->assertTrue($parsed['purchase_option']);
        $this->assertSame('Lease to Own', $parsed['lease_type']);
        $this->assertSame(60, $parsed['term_months']);
    }

    public function test_unserialized_financed_lines_are_kept_without_a_serial()
    {
        $text = implode("\n", [
            'CERTIFICATE OF ACCEPTANCE',
            'Equipment Schedule Number 004, dated as of April 09, 2026 to Master Lease',
            'Number 900123 dated June 11, 2025',
            'YEARLY RENTAL AMOUNT $1,004.92 CAD',
            '1 LAPTOP 14" X1 TESTSER001 New 906.87 4/27/2026',
            '1 RACK MOUNT KIT N/A New 98.05 5/7/2026',
        ]);

        $parsed = $this->parser()->parsePdfText($text);

        $this->assertCount(2, $parsed['lines']);
        $this->assertNull($parsed['lines'][1]['serial']);
        $this->assertSame(98.05, $parsed['lines'][1]['yearly_rental']);
        // With the unserialized line counted, the per-line sum matches the
        // stated yearly rental.
        $this->assertEqualsWithDelta(
            $parsed['yearly_rental'],
            array_sum(array_column($parsed['lines'], 'yearly_rental')),
            0.001
        );
    }

    public function test_unrecognized_text_is_rejected()
    {
        $parsed = $this->parser()->parsePdfText('An unrelated memo about printers.');

        $this->assertNull($parsed['type']);
        $this->assertNotEmpty($parsed['error']);
    }

    public function test_reads_an_exhibit_a_draft_workbook()
    {
        $path = tempnam(sys_get_temp_dir(), 'exhibit').'.xlsx';
        $this->writeExhibitWorkbook($path);

        try {
            $parsed = $this->parser()->parse($path, 'Exhibit A - Draft.xlsx');
        } finally {
            @unlink($path);
        }

        $this->assertSame(LeaseDocumentParser::TYPE_EXHIBIT_A_DRAFT, $parsed['type']);
        $this->assertSame('900123-003', $parsed['schedule_ref']);
        $this->assertSame(8137.47, $parsed['totals']['total_rent']);
        $this->assertSame(32311.8, $parsed['totals']['total_cost']);
        $this->assertSame(29893.98, $parsed['totals']['equipment_cost']);

        $this->assertCount(2, $parsed['lines']);
        $line = $parsed['lines'][0];
        $this->assertSame('TESTSER001', $line['serial']);
        $this->assertSame(906.87, $line['yearly_rental']);
        $this->assertSame(3061.22, $line['equipment_cost']);
        $this->assertSame('INV001', $line['invoice_numbers']);
        $this->assertSame('2026-04-27', $line['commencement']);
    }
}

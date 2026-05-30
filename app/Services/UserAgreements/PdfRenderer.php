<?php

namespace App\Services\UserAgreements;

use App\Models\UserAgreement;
use TCPDF;

/**
 * Branded PDF renderer for Faculty Laptop Program agreements. One method
 * per agreement_type so each layout can match the original Word templates
 * that Sohee maintains in OneDrive (Devices/Procurement/Current/Faculty
 * Program/). Output is raw PDF bytes; callers wrap in HTTP responses or
 * persist to private storage.
 */
class PdfRenderer
{
    private const LOGO_PATH = 'img/branding/ecu-logo.png';
    private const FONT = 'dejavusans';

    public function render(UserAgreement $agreement): string
    {
        return match ($agreement->agreement_type) {
            'pickup'   => $this->renderPickup($agreement),
            'upgrade'  => $this->renderUpgrade($agreement),
            'purchase' => $this->renderPurchase($agreement),
            default    => throw new \InvalidArgumentException(
                "Unsupported agreement type: {$agreement->agreement_type}"
            ),
        };
    }

    private function newPdf(string $title): TCPDF
    {
        $pdf = new TCPDF('P', 'mm', 'LETTER', true, 'UTF-8', false);
        $pdf->setRTL(false);
        $pdf->SetFontSubsetting(true);
        $pdf->SetCreator('Snipe-IT — Emily Carr University');
        $pdf->SetTitle($title);
        $pdf->SetSubject($title);
        $pdf->SetPrintHeader(false);
        $pdf->SetPrintFooter(false);
        $pdf->SetMargins(20, 20, 20);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage();

        return $pdf;
    }

    /**
     * Render the standard letterhead: ECU wordmark logo top-left and a
     * large bold agreement title to its right. Falls back to title-only
     * if the logo file is missing (defensive — should never happen).
     */
    private function drawHeader(TCPDF $pdf, string $title): void
    {
        $logoFullPath = public_path(self::LOGO_PATH);
        $logoWidthMm = 42;
        $logoHeightMm = 38;
        $startY = $pdf->GetY();

        if (is_file($logoFullPath)) {
            $pdf->Image($logoFullPath, 20, $startY, $logoWidthMm, $logoHeightMm);
        }

        $titleX = 20 + $logoWidthMm + 6;
        $pdf->SetXY($titleX, $startY + 6);
        $pdf->SetFont(self::FONT, 'B', 26);
        $pdf->MultiCell(215 - $titleX - 20, 12, $title, 0, 'L');

        $pdf->SetY($startY + $logoHeightMm + 8);
        $pdf->SetFont(self::FONT, '', 11);
    }

    private function drawSignatureBlock(TCPDF $pdf): void
    {
        $pdf->Ln(10);
        $pdf->SetFont(self::FONT, '', 11);
        $line = str_repeat('_', 40);
        $pdf->Cell(25, 8, 'Signed:', 0, 0, 'L');
        $pdf->Cell(0, 8, $line, 0, 1, 'L');
        $pdf->Ln(3);
        $pdf->Cell(25, 8, 'Dated:', 0, 0, 'L');
        $pdf->Cell(0, 8, $line, 0, 1, 'L');
    }

    private function renderPickup(UserAgreement $agreement): string
    {
        $vars = $agreement->mergeVariables();
        $pdf = $this->newPdf('Faculty Laptop Receipt Acknowledgment');
        $this->drawHeader($pdf, 'Faculty Laptop Receipt Acknowledgment');

        $intro = sprintf(
            'I, <b>%s</b>, acknowledge receipt of a laptop from Emily Carr University with the serial # <b>%s</b> and asset tag <b>%s</b>.',
            $this->e($vars['faculty_name']),
            $this->e($vars['serial']),
            $this->e($vars['asset_tag'])
        );
        $pdf->writeHTML($intro, true, false, true, false, '');
        $pdf->Ln(3);
        $pdf->writeHTML(
            'I will return the laptop promptly to the University upon employment termination. Laptop leases are 4 years in duration. I will return the laptop to the University promptly after receiving a laptop return request in the year the laptop\'s lease is expiring. I understand that I may be requested to briefly return the laptop for administrative or technical reasons.',
            true, false, true, false, ''
        );
        $pdf->Ln(4);

        $this->section($pdf, 'COST',
            'The program covers the full cost of the base model we offer. Laptop models that incur additional charge, I here by agree to pay them out of payroll deductions over the course of the next year.'
        );
        $this->section($pdf, 'CARE',
            'I will handle the laptop with due care and take precautions against possible damages. The leasing company will accept returned end of lease laptops with &ldquo;normal wear and tear&rdquo; defined as:<ul>'.
            '<li>Faded lettering on keyboard</li>'.
            '<li>Minor scratches on cover or base</li>'.
            '<li>Minor scratches on monitor screen</li>'.
            '<li>Removable ECUAD labels or tags.</li></ul>'.
            'Normal wear and tear does <i>not</i> include:<ul>'.
            '<li>Broken hinge or latch</li>'.
            '<li>Cracked lid or frame</li>'.
            '<li>The addition of non-ECUAD stickers, labels, or tags</li>'.
            '<li>Missing or broken components (power adapters)</li>'.
            '<li>More than minor scratches to the screen or case</li></ul>'
        );
        $this->section($pdf, 'PHYSICAL SECURITY',
            'I will take precautions to reduce the possibilities of theft. I will not leave the laptop unattended in a vehicle, and will consider security and take precautions when I leave the laptop unattended. I will promptly notify my Dean (or Supervisor), Facilities, and the Information Technology Service\'s Help Desk if the laptop is missing or stolen. If the laptop is stolen, I will file a police report and provide Facilities and ITS with a copy of the report.'
        );
        $this->section($pdf, 'DATA SECURITY',
            'I will take precautions to encrypt sensitive information on the laptop. Generally, information that has the potential for privacy concerns or could negatively impact the University\'s security, reputation, and business should be encrypted on your laptop.'
        );
        $this->section($pdf, 'SECURITY THREATS',
            'I will not disable or alter the laptops configured anti-virus software. I will not disable the laptops configured operating system updates. I will regularly check for operating system updates. I will regularly acknowledge and proceed with the installation of operating system updates. I will contact ITS with any concerns regarding the anti-virus software, operating system updates, or any other security related issue.'
        );
        $this->section($pdf, 'SOFTWARE',
            'I will abide by all software license agreements associated with the software installed on the laptop and/or provided to me on media. I will not install unlicensed software on the laptop.'
        );

        $this->drawSignatureBlock($pdf);

        return $pdf->Output('user-agreement-pickup.pdf', 'S');
    }

    private function renderUpgrade(UserAgreement $agreement): string
    {
        $vars = $agreement->mergeVariables();
        $upgradeAmount = (float) ($agreement->top_up_amount ?? 0);
        $semiMonthly = $upgradeAmount > 0 ? $upgradeAmount / 24 : 0;
        $semiMonthlyStr = '$'.number_format($semiMonthly, 2);

        $checkLump = $agreement->payment_method === 'pay_in_full' ? '&#9746;' : '&#9744;';
        $checkSemi = $agreement->payment_method === 'pay_in_full' ? '&#9744;' : '&#9746;';

        $pdf = $this->newPdf('Faculty Laptop Upgrade Agreement');
        $this->drawHeader($pdf, 'Faculty Laptop Upgrade Agreement');

        $intro = sprintf(
            'I, <b>%s</b>, hereby acknowledge receipt of a laptop from Emily Carr University of Art + Design. I agree to repay the additional costs faced by Emily Carr to lease this upgraded laptop <b>%s</b> | <b>%s</b> by way of a 12 month interest-free loan, or through one lump sum payment. The conditions and provisions laid out below apply to this agreement:',
            $this->e($vars['faculty_name']),
            $this->e($vars['serial']),
            $this->e($vars['asset_tag'])
        );
        $pdf->writeHTML($intro, true, false, true, false, '');
        $pdf->Ln(4);

        $clause1 = sprintf(
            '<b>1.</b> &nbsp; I agree to repay the loan which totals the amount of: <b>%s</b> to be collected from me by payroll deduction in one of the two options:',
            $vars['upgrade_amount']
        );
        $pdf->writeHTML($clause1, true, false, true, false, '');
        $pdf->Ln(2);
        $pdf->writeHTML(
            '&nbsp;&nbsp;&nbsp;&nbsp;<font size="14">'.$checkLump.'</font>&nbsp;&nbsp;Pay in full in one single payroll deduction',
            true, false, true, false, ''
        );
        $pdf->writeHTML(
            '&nbsp;&nbsp;&nbsp;&nbsp;<font size="14">'.$checkSemi.'</font>&nbsp;&nbsp;Pay in semi-monthly installments over a loan period of 12 months and authorize 24 semi-monthly installment payments of <b>'.$semiMonthlyStr.'</b>',
            true, false, true, false, ''
        );
        $pdf->Ln(4);

        $pdf->writeHTML(
            '<b>2.</b> &nbsp; I agree to return the laptop to Emily Carr should cessation of employment with Emily Carr occur during the loan period. Otherwise I agree to repay the loan as per above and return the laptop after a 48 month term, unless Emily Carr approval is granted stating otherwise.',
            true, false, true, false, ''
        );
        $pdf->Ln(3);

        $pdf->writeHTML(
            '<b>3.</b> &nbsp; I accept that a taxable benefit will accrue to me if I choose to receive an interest free loan and I agree not to hold Emily Carr responsible for any rulings, decisions, or actions by Canada Revenue Agency arising in this regard.',
            true, false, true, false, ''
        );

        $this->drawSignatureBlock($pdf);

        return $pdf->Output('user-agreement-upgrade.pdf', 'S');
    }

    private function renderPurchase(UserAgreement $agreement): string
    {
        $vars = $agreement->mergeVariables();
        $pdf = $this->newPdf('Faculty Laptop Purchase Agreement');
        $this->drawHeader($pdf, 'Faculty Laptop Purchase Agreement');

        $body = sprintf(
            'I, <b>%s</b>, hereby acknowledge I would like to purchase the laptop <b>%s</b> with serial number <b>%s</b> which I have been using as my assigned device under the Faculty Laptop Program.',
            $this->e($vars['faculty_name']),
            $this->e($vars['asset_tag']),
            $this->e($vars['serial'])
        );
        $pdf->writeHTML($body, true, false, true, false, '');
        $pdf->Ln(4);

        $pdf->writeHTML(
            sprintf(
                'I agree to pay the residual cost of <b>%s</b> %s.',
                $vars['buyout_cost'],
                $this->e($vars['payment_phrase'])
            ),
            true, false, true, false, ''
        );
        $pdf->Ln(3);

        $pdf->writeHTML(
            'I agree that I am purchasing the laptop in its as-is condition.',
            true, false, true, false, ''
        );
        $pdf->Ln(3);

        $pdf->writeHTML(
            'I agree and understand that this device will be un-managed by Emily Carr and thus is to be treated as a personally owned device and that ITS is no longer able and willing to provide technical support for this laptop.',
            true, false, true, false, ''
        );

        $this->drawSignatureBlock($pdf);

        return $pdf->Output('user-agreement-purchase.pdf', 'S');
    }

    private function section(TCPDF $pdf, string $heading, string $bodyHtml): void
    {
        $pdf->SetFont(self::FONT, 'B', 11);
        $pdf->writeHTML($heading, true, false, true, false, '');
        $pdf->SetFont(self::FONT, '', 11);
        $pdf->writeHTML($bodyHtml, true, false, true, false, '');
        $pdf->Ln(3);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

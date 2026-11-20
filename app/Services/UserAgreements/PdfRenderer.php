<?php

namespace App\Services\UserAgreements;

use App\Helpers\Helper;
use App\Models\Setting;
use App\Models\UserAgreement;
use Illuminate\Support\Facades\Storage;
use TCPDF;

/** Branded layout for the canonical, captured agreement text. */
class PdfRenderer
{
    private const LOGO_PATH = 'img/branding/ecu-logo.png';

    /** The PDF metadata's institution when Settings → Branding has no site name. */
    private const DEFAULT_INSTITUTION = 'Emily Carr University';

    private const FONT = 'dejavusans';

    public function render(UserAgreement $agreement): string
    {
        if (! in_array($agreement->agreement_type, UserAgreement::AGREEMENT_TYPES, true)) {
            throw new \InvalidArgumentException('Unsupported agreement type.');
        }

        return $this->renderDocument($agreement);
    }

    private function newPdf(string $title): TCPDF
    {
        $pdf = new TCPDF('P', 'mm', 'LETTER', true, 'UTF-8', false);
        $pdf->setRTL(false);
        $pdf->SetFontSubsetting(true);
        $pdf->SetCreator('Snipe-IT — '.$this->institutionName());
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
        $logoFullPath = $this->logoPath();
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

    /**
     * Render the captured signing body, or the canonical body for an unsent draft.
     */
    private function renderDocument(UserAgreement $agreement): string
    {
        $title = $this->title($agreement->agreement_type, match ($agreement->agreement_type) {
            'pickup' => 'Faculty Laptop Receipt Acknowledgment',
            'upgrade' => 'Faculty Laptop Upgrade Agreement',
            default => 'Faculty Laptop Purchase Agreement',
        });

        $pdf = $this->newPdf($title);
        $this->drawHeader($pdf, $title);
        foreach (preg_split('/\\r\\n|\\n|\\r/', $agreement->documentBody()) as $line) {
            $pdf->writeHTML(Helper::parseEscapedMarkedown($line), true, false, true, false, '');
        }
        $this->drawSignatureBlock($pdf);

        return $pdf->Output('user-agreement-'.$agreement->agreement_type.'.pdf', 'S');
    }

    /** Render only the text and acceptance data captured at the signing boundary. */
    public function renderAccepted(UserAgreement $agreement, array $data): string
    {
        $title = $this->title($agreement->agreement_type, match ($agreement->agreement_type) {
            'pickup' => 'Faculty Laptop Receipt Acknowledgment',
            'upgrade' => 'Faculty Laptop Upgrade Agreement',
            default => 'Faculty Laptop Purchase Agreement',
        });
        $pdf = $this->newPdf($title);
        $pdf->SetAuthor((string) ($data['assigned_to'] ?? ''));
        $this->drawHeader($pdf, $title);
        $rows = [
            trans('general.assignee') => trim(($data['assigned_to'] ?? '').(! empty($data['employee_num']) ? ' ('.$data['employee_num'].')' : '')),
            trans('general.email') => $data['email'] ?? '',
            trans('general.company') => $data['company_name'] ?? '',
            trans('general.name') => $data['item_name'] ?? '',
            trans('general.asset_model') => $data['item_model'] ?? '',
            trans('general.asset_tag') => $data['item_tag'] ?? '',
            trans('admin/hardware/form.serial') => $data['item_serial'] ?? '',
        ];
        foreach (($data['custom_fields'] ?? []) as $field) {
            if (isset($field['label'], $field['value']) && $field['value'] !== '') {
                $rows[(string) $field['label']] = (string) $field['value'];
            }
        }
        if (($data['qty'] ?? 0) > 1) {
            $rows[trans('general.qty')] = (string) $data['qty'];
        }
        $html = '<table cellpadding="4">';
        foreach ($rows as $label => $value) {
            if ($value !== '') {
                $html .= '<tr><td width="28%"><b>'.e($label).'</b></td><td width="72%">'.e($value).'</td></tr>';
            }
        }
        $pdf->writeHTML($html.'</table><br><hr><br>');
        foreach (preg_split('/\\r\\n|\\n|\\r/', (string) ($data['eula'] ?? '')) as $line) {
            $pdf->writeHTML(Helper::parseEscapedMarkedown($line), true, false, true, false, '');
        }
        $pdf->Ln(6);
        $pdf->writeHTML('<hr>');
        if (! empty($data['signature'])) {
            $pdf->Image('@'.base64_decode($data['signature']), $pdf->GetX(), $pdf->GetY(), 55);
            $pdf->Ln(20);
        }
        $pdf->writeHTML('<b>'.e(trans('general.assignee')).':</b> '.e($data['assigned_to'] ?? ''));
        $pdf->writeHTML('<b>'.e(trans('general.assigned_date')).':</b> '.e($data['check_out_date'] ?? ''));
        $pdf->writeHTML('<b>'.e(trans('general.accepted_date')).':</b> '.e($data['accepted_date'] ?? ''));
        if (! empty($data['note'])) {
            $pdf->writeHTML('<b>'.e(trans('general.notes')).':</b> '.e($data['note']));
        }

        return $pdf->Output('accepted-user-agreement.pdf', 'S');
    }

    /** A Settings → Agreements field, or null when it is blank. */
    private function override(string $type, string $part): ?string
    {
        $value = trim((string) (Setting::getSettings()->{'agreement_'.$type.'_'.$part} ?? ''));

        return $value === '' ? null : $value;
    }

    /** The saved title for a type, else the built-in one. */
    private function title(string $type, string $builtIn): string
    {
        return $this->override($type, 'title') ?? $builtIn;
    }

    /** The branding site name, else the name the PDFs have always carried. */
    private function institutionName(): string
    {
        $name = trim((string) (Setting::getSettings()->site_name ?? ''));

        return $name !== '' ? $name : self::DEFAULT_INSTITUTION;
    }

    /** The acceptance PDF logo from Settings → Branding when one is uploaded, else the bundled one. */
    private function logoPath(): string
    {
        $logo = (string) (Setting::getSettings()->acceptance_pdf_logo ?? '');
        if ($logo !== '' && Storage::disk('public')->exists($logo)) {
            $uploaded = public_path('uploads/'.basename($logo));
            if (is_file($uploaded)) {
                return $uploaded;
            }
        }

        return public_path(self::LOGO_PATH);
    }

}

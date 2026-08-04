<?php

namespace App\Services\Leasing;

use App\Models\Asset;
use App\Models\Contract;
use App\Models\ContractAttribute;
use App\Models\ContractSerial;
use App\Models\CsiSchedule;
use App\Models\LeaseSchedule;
use App\Models\Supplier;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Turns a parsed lessor document into lease schedule and contract state.
 * This is the only write path from document intake onto the contract
 * register, shared by the web drop zone and the API so both behave
 * identically:
 *
 *   - schedule_agreement        → open or update the LeaseSchedule for that
 *                                 ref and mark it signed.
 *   - certificate_of_acceptance → finalize: create or update the per-schedule
 *                                 contract (under the lease-number umbrella
 *                                 contract), write serials and attributes,
 *                                 flip the schedule to active. The signed
 *                                 document is mandatory.
 *   - exhibit_a_draft           → reconcile: enrich the contract (or, before
 *                                 finalization, the schedule) with financed
 *                                 cost totals and per-serial cost detail.
 *
 * Every apply() returns the touched models plus human-readable warnings —
 * mismatches against the lessor API mirror and serials Snipe doesn't know —
 * so the confirm screen and API callers can surface them without failing
 * the intake.
 */
class ScheduleIntake
{
    private const CONTRACT_STORAGE_PATH = 'private_uploads/contracts/';

    private const SCHEDULE_STORAGE_PATH = 'private_uploads/lease-schedules/';

    private const THEME = 'Devices Leases';

    /**
     * @param  array  $parsed  Output of LeaseDocumentParser::parse(), possibly
     *                         with user-edited field overrides merged in.
     */
    public function apply(array $parsed, ?UploadedFile $document = null): array
    {
        if (empty($parsed['schedule_ref'])) {
            throw new \RuntimeException(trans('admin/lease-intake/general.no_schedule_ref'));
        }

        return match ($parsed['type'] ?? null) {
            LeaseDocumentParser::TYPE_SCHEDULE_AGREEMENT => $this->open($parsed, $document),
            LeaseDocumentParser::TYPE_CERTIFICATE_OF_ACCEPTANCE => $this->finalize($parsed, $document),
            LeaseDocumentParser::TYPE_EXHIBIT_A_DRAFT => $this->reconcile($parsed, $document),
            default => throw new \RuntimeException(trans('admin/lease-intake/general.unrecognized_document')),
        };
    }

    // ─── Open (signed schedule agreement) ───────────────────────────────

    private function open(array $parsed, ?UploadedFile $document): array
    {
        $schedule = $this->schedule($parsed);

        $schedule->expected_acquisition_cost = $parsed['cost_cap'] ?? $schedule->expected_acquisition_cost;

        // An executed agreement means the schedule is signed — but never
        // walk an already-finalized schedule back from active.
        if ($schedule->lifecycle_stage !== 'active') {
            $schedule->lifecycle_stage = 'signed';
            $schedule->signed_at = $schedule->signed_at ?? ($parsed['dated_as_of'] ?? now()->toDateString());
        }

        $schedule->save();

        if ($document) {
            $this->attach($schedule, self::SCHEDULE_STORAGE_PATH, 'lease-schedule', $document,
                trans('admin/lease-intake/general.agreement_note', ['ref' => $parsed['schedule_ref']]));
        }

        return [
            'action' => 'opened',
            'schedule' => $schedule,
            'contract' => $schedule->contract_id ? Contract::find($schedule->contract_id) : null,
            'warnings' => $this->mirrorWarnings($parsed),
        ];
    }

    // ─── Finalize (certificate of acceptance) ───────────────────────────

    private function finalize(array $parsed, ?UploadedFile $document): array
    {
        if (! $document) {
            throw new \RuntimeException(trans('admin/lease-intake/general.document_required'));
        }

        return DB::transaction(function () use ($parsed, $document) {
            $ref = $parsed['schedule_ref'];
            $schedule = $this->schedule($parsed);

            $contract = $this->contractForRef($ref, $schedule) ?? $this->buildContract($parsed);

            $contract->fill(array_filter([
                'schedule_number' => $ref,
                'start_date' => $parsed['term_start'] ?? null,
                'end_date' => $parsed['term_end'] ?? null,
            ]));
            $contract->supplier_id = $contract->supplier_id ?? $this->supplierId($parsed['lessor'] ?? null);
            $contract->parent_contract_id = $contract->parent_contract_id
                ?? $this->umbrella($parsed)?->id;
            $contract->is_active = empty($parsed['term_end']) || $parsed['term_end'] >= now()->toDateString();

            if (! $contract->save()) {
                throw new \RuntimeException($contract->getErrors()->first());
            }

            $this->writeAttributes($contract, array_filter([
                'lease_number' => $parsed['lease_number'] ?? null,
                'lease_type' => $this->leaseType($parsed),
                'term_months' => $parsed['term_months'] ?? null,
                'yearly_rental' => $parsed['yearly_rental'] ?? null,
                'stip_loss_value' => $parsed['stip_loss_value'] ?? null,
                'equipment_location' => $parsed['equipment_location'] ?? null,
            ]));

            $this->writeSerials($contract, $parsed['lines'] ?? [], 'certificate-of-acceptance');

            $schedule->lifecycle_stage = 'active';
            $schedule->signed_at = $schedule->signed_at ?? ($parsed['dated_as_of'] ?? now()->toDateString());
            $schedule->contract_id = $contract->id;
            $schedule->expected_asset_count = $schedule->expected_asset_count ?? count($parsed['lines'] ?? []);
            $schedule->save();

            $note = trans('admin/lease-intake/general.acceptance_note', ['ref' => $ref]);
            $this->attach($contract, self::CONTRACT_STORAGE_PATH, 'contract', $document, $note);
            // Also file it on the schedule so the existing Exhibit A serial
            // diff tooling can read it from there.
            $this->attach($schedule, self::SCHEDULE_STORAGE_PATH, 'lease-schedule', $document, $note);

            return [
                'action' => 'finalized',
                'schedule' => $schedule,
                'contract' => $contract->fresh(),
                'warnings' => array_merge($this->mirrorWarnings($parsed), $this->lineWarnings($parsed)),
            ];
        });
    }

    // ─── Reconcile (Exhibit A draft workbook) ───────────────────────────

    private function reconcile(array $parsed, ?UploadedFile $document): array
    {
        $ref = $parsed['schedule_ref'];
        $schedule = $this->schedule($parsed);
        $contract = $this->contractForRef($ref, $schedule);
        $totals = $parsed['totals'] ?? [];

        if ($contract) {
            if (isset($totals['total_cost'])) {
                // The financed total (equipment + soft cost) is what budget
                // reconciliation compares against purchase orders.
                $contract->total_cost = $totals['total_cost'];
                $contract->save();
            }

            $this->writeAttributes($contract, array_filter([
                'equipment_cost' => $totals['equipment_cost'] ?? null,
                'soft_cost' => $totals['soft_cost'] ?? null,
                'total_rent' => $totals['total_rent'] ?? null,
                'financed_interim_rent' => $totals['financed_interim_rent'] ?? null,
                'lease_rate_factor' => $totals['lease_rate_factor'] ?? null,
            ]));

            $this->writeSerials($contract, $parsed['lines'] ?? [], 'exhibit-a-draft');

            if ($document) {
                $this->attach($contract, self::CONTRACT_STORAGE_PATH, 'contract', $document,
                    trans('admin/lease-intake/general.exhibit_note', ['ref' => $ref]));
            }

            $schedule->contract_id = $schedule->contract_id ?? $contract->id;
            $schedule->save();
        } else {
            $schedule->expected_acquisition_cost = $totals['total_cost'] ?? $schedule->expected_acquisition_cost;
            $schedule->expected_asset_count = count($parsed['lines'] ?? []) ?: $schedule->expected_asset_count;
            $schedule->save();

            if ($document) {
                $this->attach($schedule, self::SCHEDULE_STORAGE_PATH, 'lease-schedule', $document,
                    trans('admin/lease-intake/general.exhibit_note', ['ref' => $ref]));
            }
        }

        return [
            'action' => 'reconciled',
            'schedule' => $schedule,
            'contract' => $contract?->fresh(),
            'warnings' => array_merge($this->mirrorWarnings($parsed), $this->lineWarnings($parsed)),
        ];
    }

    // ─── Shared pieces ──────────────────────────────────────────────────

    /** Find-or-start the LeaseSchedule row for the parsed ref, filling gaps. */
    private function schedule(array $parsed): LeaseSchedule
    {
        $schedule = LeaseSchedule::firstOrNew(['schedule_ref' => $parsed['schedule_ref']]);

        if (! $schedule->exists) {
            $schedule->lifecycle_stage = 'draft';
            $schedule->created_by = auth()->id();
        }

        $schedule->lessor = $schedule->lessor ?: $this->canonicalLessor($parsed['lessor'] ?? null);
        $schedule->lease_type = $schedule->lease_type ?: $this->leaseType($parsed);
        $schedule->term_months = $schedule->term_months ?: ($parsed['term_months'] ?? null);
        $schedule->received_at = $schedule->received_at ?? ($parsed['dated_as_of'] ?? now()->toDateString());

        return $schedule;
    }

    /** The contract already holding this schedule ref, if any. */
    private function contractForRef(string $ref, LeaseSchedule $schedule): ?Contract
    {
        if ($schedule->contract_id && ($existing = Contract::find($schedule->contract_id))) {
            return $existing;
        }

        return Contract::where('schedule_number', $ref)->first();
    }

    /**
     * Start a new per-schedule contract named into the existing
     * "Devices Leases FY.. #N" series, N continuing from the highest
     * number already used for that fiscal year.
     */
    private function buildContract(array $parsed): Contract
    {
        $fiscalYear = $this->fiscalYearShort($parsed['term_start'] ?? null);
        $series = self::THEME.' '.$fiscalYear;

        $next = Contract::withTrashed()
            ->where('name', 'like', $series.' #%')
            ->pluck('name')
            ->map(fn ($name) => (int) substr($name, strrpos($name, '#') + 1))
            ->max() + 1;
        $numbered = sprintf('%s #%02d', $series, $next);

        return new Contract([
            'contract_number' => $numbered,
            'name' => $numbered,
            'theme' => self::THEME,
            'product' => $parsed['schedule_ref'],
            'fiscal_year' => $fiscalYear,
            'type' => 'lease',
            'currency' => 'CAD',
            'source' => 'snipe',
            'created_by' => auth()->id(),
        ]);
    }

    /** Find-or-create the umbrella contract for the lease number. */
    private function umbrella(array $parsed): ?Contract
    {
        $leaseNumber = $parsed['lease_number'] ?? null;

        if (! $leaseNumber) {
            return null;
        }

        $umbrella = Contract::firstOrNew(['contract_number' => $leaseNumber]);

        if (! $umbrella->exists) {
            $umbrella->name = self::THEME.' '.$leaseNumber;
            $umbrella->theme = self::THEME;
            $umbrella->is_synthesized = true;
            $umbrella->is_active = true;
            $umbrella->type = 'lease';
            $umbrella->source = 'snipe';
            $umbrella->supplier_id = $this->supplierId($parsed['lessor'] ?? null);
            $umbrella->created_by = auth()->id();
            $umbrella->save();
        }

        return $umbrella;
    }

    /**
     * Resolve the lessor to an existing supplier without creating
     * near-duplicates: "Acme Leasing Canada Ltd." on the document matches
     * an existing "Acme Leasing" supplier by prefix either way round.
     */
    private function supplierFor(?string $lessor): ?Supplier
    {
        $lessor = trim((string) $lessor);

        if ($lessor === '') {
            return null;
        }

        return Supplier::query()
            ->get(['id', 'name'])
            ->first(fn ($s) => stripos($lessor, $s->name) === 0 || stripos($s->name, $lessor) === 0);
    }

    private function supplierId(?string $lessor): ?int
    {
        if (trim((string) $lessor) === '') {
            return null;
        }

        $match = $this->supplierFor($lessor);

        return $match->id ?? Supplier::create(['name' => trim($lessor)])->id;
    }

    /** Prefer the estate's supplier name over the document's longer legal name. */
    private function canonicalLessor(?string $lessor): ?string
    {
        return $this->supplierFor($lessor)->name ?? $lessor;
    }

    private function leaseType(array $parsed): ?string
    {
        if (! empty($parsed['lease_type'])) {
            return $parsed['lease_type'];
        }

        // The two schedule types run fixed terms: 48 months returns to the
        // lessor, 60 months ends in a $1 buyout.
        return match ($parsed['term_months'] ?? null) {
            48 => 'Lease to Return',
            60 => 'Lease to Own',
            default => null,
        };
    }

    private function writeAttributes(Contract $contract, array $attributes): void
    {
        foreach ($attributes as $name => $value) {
            ContractAttribute::updateOrCreate(
                ['contract_id' => $contract->id, 'name' => $name],
                ['value' => (string) $value]
            );
        }
    }

    private function writeSerials(Contract $contract, array $lines, string $source): void
    {
        foreach ($lines as $line) {
            if (empty($line['serial'])) {
                continue;
            }

            $notes = collect([
                $line['description'] ?? null,
                isset($line['invoice_numbers']) ? trans('admin/lease-intake/general.serial_invoices', ['invoices' => $line['invoice_numbers']]) : null,
                isset($line['total_cost']) ? trans('admin/lease-intake/general.serial_cost', ['cost' => number_format($line['total_cost'], 2)]) : null,
            ])->filter()->implode(' — ');

            ContractSerial::updateOrCreate(
                ['contract_id' => $contract->id, 'serial' => $line['serial']],
                ['source' => $source, 'notes' => $notes ?: null]
            );
        }
    }

    /** Store the upload alongside the object and log it, mirroring the documents tab. */
    private function attach(Contract|LeaseSchedule $item, string $storagePath, string $prefix, UploadedFile $document, string $note): void
    {
        if (! Storage::exists($storagePath)) {
            Storage::makeDirectory($storagePath);
        }

        $extension = $document->getClientOriginalExtension();
        $filename = $prefix.'-'.$item->id.'-'.str_random(8).'-'
            .str_slug(basename($document->getClientOriginalName(), '.'.$extension)).'.'
            .($document->guessExtension() ?: $extension ?: 'pdf');

        Storage::put($storagePath.$filename, file_get_contents($document));

        $item->logUpload($filename, $note);
    }

    /** Fiscal year runs April to March: 2026-07-01 → "FY26-27". */
    private function fiscalYearShort(?string $date): string
    {
        $d = $date ? Carbon::parse($date) : now();
        $start = $d->month >= 4 ? $d->year : $d->year - 1;

        return sprintf('FY%02d-%02d', $start % 100, ($start + 1) % 100);
    }

    // ─── Cross-checks ───────────────────────────────────────────────────

    /**
     * All cross-check warnings for a parsed document, without writing
     * anything — used by the preview step so surprises surface before
     * commit, not after.
     */
    public function warnings(array $parsed): array
    {
        if (empty($parsed['schedule_ref'])) {
            return [];
        }

        return array_merge($this->mirrorWarnings($parsed), $this->lineWarnings($parsed));
    }

    /** Compare parsed terms against the lessor API mirror, if it has the schedule. */
    private function mirrorWarnings(array $parsed): array
    {
        $warnings = [];
        $mirror = CsiSchedule::where('schedule_name', $parsed['schedule_ref'])->first();

        if (! $mirror) {
            $warnings[] = trans('admin/lease-intake/general.warning_not_in_mirror', ['ref' => $parsed['schedule_ref']]);

            return $warnings;
        }

        foreach ([['term_start', 'term_start_date'], ['term_end', 'term_end_date']] as [$key, $column]) {
            $ours = $parsed[$key] ?? null;
            $theirs = $mirror->{$column}?->toDateString();
            if ($ours && $theirs && $ours !== $theirs) {
                $warnings[] = trans('admin/lease-intake/general.warning_mirror_date', [
                    'field' => $key, 'document' => $ours, 'mirror' => $theirs,
                ]);
            }
        }

        return $warnings;
    }

    /** Per-serial sanity: unknown assets and rental totals that don't add up. */
    private function lineWarnings(array $parsed): array
    {
        $warnings = [];
        $lines = $parsed['lines'] ?? [];

        if ($lines === []) {
            return $warnings;
        }

        $serials = collect($lines)->pluck('serial')->filter();
        $known = Asset::whereIn('serial', $serials)->pluck('serial')
            ->map(fn ($s) => strtoupper($s))->flip();
        $missing = $serials->reject(fn ($s) => isset($known[strtoupper($s)]))->values();

        if ($missing->isNotEmpty()) {
            $warnings[] = trans('admin/lease-intake/general.warning_serials_missing', [
                'count' => $missing->count(),
                'serials' => $missing->implode(', '),
            ]);
        }

        $lineTotal = collect($lines)->sum(fn ($l) => $l['yearly_rental'] ?? 0);
        $stated = $parsed['yearly_rental'] ?? ($parsed['totals']['total_rent'] ?? null);

        if ($stated !== null && $lineTotal > 0 && abs($lineTotal - $stated) > 0.05) {
            $warnings[] = trans('admin/lease-intake/general.warning_rental_sum', [
                'lines' => number_format($lineTotal, 2),
                'stated' => number_format($stated, 2),
            ]);
        }

        return $warnings;
    }
}

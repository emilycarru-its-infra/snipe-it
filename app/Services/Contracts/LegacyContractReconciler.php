<?php

namespace App\Services\Contracts;

use App\Models\Contract;
use Illuminate\Support\Facades\DB;

/**
 * Files the legacy license-migration contracts under the TDX product family
 * that superseded them, and takes them out of the active set.
 *
 * `licenses:migrate-saas-to-contracts` moved every SaaS/service license into
 * the contracts table as its own row, numbered `LIC-<license_id>`. TDX then
 * became the source of truth for contracts and `tdx-to-snipe-contracts`
 * started ingesting the same agreements again, keyed on `tdx_id`. The two
 * populations have never met: the ingest is barred from touching non-TDX
 * rows by the SSOT gate, its duplicate collapse only groups rows that parse
 * into a product and a fiscal year, and its parent synthesis only indexes
 * TDX children. So a product with a TDX contract and four surviving `LIC-*`
 * rows renews as five separate contracts, and the renewal alert says so.
 *
 * Matching is on product name, with the supplier as a veto rather than a
 * requirement: two rows naming the same product under two different
 * suppliers are not the same agreement, but a legacy row with no supplier
 * on file still matches. A matched row is parented to the TDX row's
 * synthesized (provider, product) parent, has its product and supplier
 * filled in where they were blank, and is deactivated — reversible, keeps
 * the row and its rebound action-log history addressable, and stops it
 * alerting. Rows with no TDX counterpart are left exactly as they are.
 */
class LegacyContractReconciler
{
    /**
     * Sweep the legacy rows and retire the ones a TDX contract now covers.
     *
     * @param  bool  $write  When false (default) nothing is written — the
     *                       report describes what a --write run would do.
     */
    public function run(bool $write = false): LegacyContractReconcilerReport
    {
        $report = new LegacyContractReconcilerReport;

        $legacy = Contract::query()
            ->where('source', 'manual')
            ->whereNull('tdx_id')
            ->where('contract_number', 'LIKE', 'LIC-%')
            ->where('is_active', true)
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $report->scanned = $legacy->count();

        if ($report->scanned === 0) {
            return $report;
        }

        $families = $this->tdxFamiliesByProduct();

        foreach ($legacy as $contract) {
            $match = $this->matchFor($contract, $families);

            if (! $match) {
                $report->rows[] = $this->row($contract, 'keep', null);

                continue;
            }

            $report->matched++;
            $report->rows[] = $this->row($contract, 'retire', $match);

            if (! $write) {
                continue;
            }

            DB::transaction(function () use ($contract, $match) {
                $contract->parent_contract_id = $match->parent_contract_id ?: $contract->parent_contract_id;
                $contract->product = $contract->product ?: $match->product;
                $contract->supplier_id = $contract->supplier_id ?: $match->supplier_id;
                $contract->is_active = false;
                $contract->notes = trim(($contract->notes ? $contract->notes."\n\n" : '')
                    .'Superseded by '.$match->contract_number.' (TDX '.$match->tdx_id.'). '
                    .'Deactivated by contracts:reconcile-legacy-licenses on '.now()->toDateString().'.');
                $contract->save();
            });

            $report->written++;
        }

        return $report;
    }

    /**
     * Live TDX contracts grouped by lowercased product name. The newest end
     * date wins when a product has several fiscal years on file, so the
     * legacy row inherits the current parent and supplier.
     *
     * @return array<string, \Illuminate\Support\Collection<int, Contract>>
     */
    private function tdxFamiliesByProduct(): array
    {
        return Contract::query()
            ->where('source', 'tdx')
            ->whereNotNull('product')
            ->where('product', '<>', '')
            ->with('parentContract')
            ->orderByDesc('end_date')
            ->get()
            ->groupBy(fn (Contract $c) => mb_strtolower(trim($c->product)))
            ->all();
    }

    /**
     * The TDX contract that supersedes this legacy row, or null.
     *
     * The legacy rows carry the product in `name` (the license name they
     * were migrated from) and nothing in `product`, which is why the
     * ingest's own dedup can't see them.
     */
    private function matchFor(Contract $contract, array $families): ?Contract
    {
        $key = mb_strtolower(trim((string) ($contract->product ?: $contract->name)));

        if ($key === '' || ! isset($families[$key])) {
            return null;
        }

        $candidates = $families[$key];

        // Supplier as a veto: same product name under a different supplier is
        // a different agreement. A blank supplier on either side is not
        // evidence of a mismatch, so it doesn't block.
        if ($contract->supplier_id) {
            $sameSupplier = $candidates->first(
                fn (Contract $c) => $c->supplier_id === null || (int) $c->supplier_id === (int) $contract->supplier_id
            );

            return $sameSupplier;
        }

        return $candidates->first();
    }

    private function row(Contract $contract, string $action, ?Contract $match): array
    {
        return [
            'id' => (int) $contract->id,
            'contract_number' => (string) $contract->contract_number,
            'name' => (string) $contract->name,
            'end_date' => $contract->end_date?->toDateString(),
            'action' => $action,
            'matched_tdx_id' => $match?->tdx_id ? (int) $match->tdx_id : null,
            'matched_contract_number' => $match?->contract_number,
            'parent_id' => $match?->parent_contract_id ? (int) $match->parent_contract_id : null,
            'parent_name' => $match?->parentContract?->name,
        ];
    }
}

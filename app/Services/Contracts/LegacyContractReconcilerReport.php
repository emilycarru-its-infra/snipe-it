<?php

namespace App\Services\Contracts;

/**
 * Result of a LegacyContractReconciler::run() pass.
 */
class LegacyContractReconcilerReport
{
    /** Legacy license-migration contracts examined. */
    public int $scanned = 0;

    /** Of those, how many matched a TDX-sourced contract for the same product. */
    public int $matched = 0;

    /** How many were actually written (0 on a preview run). */
    public int $written = 0;

    /**
     * Per-row decisions, in the order they were made. `action` is 'retire'
     * for a matched row and 'keep' for one with no TDX counterpart.
     *
     * @var array<int, array{
     *     id:int, contract_number:string, name:string, end_date:?string,
     *     action:string, matched_tdx_id:?int, matched_contract_number:?string,
     *     parent_id:?int, parent_name:?string
     * }>
     */
    public array $rows = [];

    /** Rows with no TDX counterpart — left active, listed for a human. */
    public function unmatched(): array
    {
        return array_values(array_filter($this->rows, fn ($r) => $r['action'] === 'keep'));
    }

    /** Rows that were (or would be) retired under a TDX product family. */
    public function retired(): array
    {
        return array_values(array_filter($this->rows, fn ($r) => $r['action'] === 'retire'));
    }
}

<?php

namespace App\Services\Leasing;

/**
 * Result of a LessorBackfillService::run() pass.
 */
class LessorBackfillReport
{
    /** Leased assets (missing a lessor) examined. */
    public int $scanned = 0;

    /** Of those, how many had a lessor derivable from the contract-ID prefix. */
    public int $resolved = 0;

    /** How many were actually written (0 on a preview run). */
    public int $written = 0;

    /**
     * Assets that look leased but whose lessor couldn't be derived — need a
     * manual lessor assignment.
     *
     * @var array<int, array{id:int, asset_tag:string, contract_id:string}>
     */
    public array $unresolved = [];
}

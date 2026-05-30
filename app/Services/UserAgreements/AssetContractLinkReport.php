<?php

namespace App\Services\UserAgreements;

class AssetContractLinkReport
{
    public int $assetsScanned          = 0;
    public int $assetsSkipped          = 0;
    public int $bridgesAlreadyPresent  = 0;
    public int $bridgesPlanned         = 0;
    public int $bridgesCreated         = 0;
    public int $contractsPlanned       = 0;
    public int $contractsCreated       = 0;

    /** @var array<int, array{contract_id:int, asset_id:int}> */
    public array $createdPairs = [];

    public function toArray(): array
    {
        return [
            'assets_scanned'           => $this->assetsScanned,
            'assets_skipped'           => $this->assetsSkipped,
            'bridges_already_present'  => $this->bridgesAlreadyPresent,
            'bridges_planned'          => $this->bridgesPlanned,
            'bridges_created'          => $this->bridgesCreated,
            'contracts_planned'        => $this->contractsPlanned,
            'contracts_created'        => $this->contractsCreated,
            'created_pairs'            => $this->createdPairs,
        ];
    }
}

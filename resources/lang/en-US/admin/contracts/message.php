<?php

return [
    'does_not_exist' => 'Contract does not exist or you do not have permission to view it.',
    'not_found'      => 'Contract not found.',

    'create' => [
        'error'   => 'Contract was not created, please try again.',
        'success' => 'Contract created successfully.',
    ],

    'update' => [
        'error'   => 'Contract was not updated, please try again.',
        'success' => 'Contract updated successfully.',
    ],

    'upsert' => [
        'success' => 'Contract upserted from TDX successfully.',
        'skipped_non_tdx_source' => 'Skipped — contract is owned by source=:source, refusing to overwrite from TDX.',
    ],

    'delete' => [
        'error'   => 'Contract could not be deleted, please try again.',
        'success' => 'Contract deleted successfully.',
    ],
];

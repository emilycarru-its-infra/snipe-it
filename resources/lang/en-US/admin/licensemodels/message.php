<?php

return [
    'does_not_exist'   => 'License model does not exist.',
    'not_found'        => 'License model not found.',
    'assoc_licenses'   => 'This license model cannot be deleted — it is still referenced by :count license(s). Reassign or delete those first.',

    'create' => [
        'error'   => 'License model was not created, please try again.',
        'success' => 'License model created successfully.',
    ],

    'update' => [
        'error'   => 'License model was not updated, please try again.',
        'success' => 'License model updated successfully.',
    ],

    'delete' => [
        'confirm' => 'Are you sure you want to delete this license model? Licenses referencing it will fall back to the default "Product Key" behavior.',
        'error'   => 'License model could not be deleted, please try again.',
        'success' => 'License model deleted successfully.',
    ],
];

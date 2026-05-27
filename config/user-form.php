<?php

return [
    /*
    |--------------------------------------------------------------------------
    | User intake form gate group
    |--------------------------------------------------------------------------
    |
    | Authenticated users must belong to this Snipe group to access /user-form.
    | Leave the value empty/null to disable the form entirely.
    |
    */
    'group' => env('USER_FORM_GROUP', 'Regular Faculty'),

    /*
    |--------------------------------------------------------------------------
    | External purchase URL
    |--------------------------------------------------------------------------
    |
    | After a successful submission the user is offered a prominent link to
    | this URL, where the actual hardware purchase happens. For ECU this is
    | the CDW eStore; other deployments can point at their own catalog.
    |
    */
    'external_purchase_url' => env('USER_FORM_EXTERNAL_PURCHASE_URL', 'https://cdw.ca/emilycarru'),
];

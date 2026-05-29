<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Registered forms
    |--------------------------------------------------------------------------
    |
    | Each entry declares one form module. The 'class' references a class
    | extending App\Forms\FormDefinition that owns the form's logic, views,
    | and storage. Form ↔ group bindings (who can submit) live in the
    | form_eligibility table and are managed via Admin → Settings → Forms.
    |
    */

    'modules' => [
        'faculty-program' => [
            'class'           => \App\Forms\FacultyProgram\FacultyProgramForm::class,
            'label_key'       => 'admin/forms/faculty-program.title',
            'description_key' => 'admin/forms/faculty-program.tile_description',
            'icon'            => 'fas fa-laptop',
        ],
    ],

    'faculty_program' => [
        'external_purchase_url' => env('USER_FORM_EXTERNAL_PURCHASE_URL', 'https://cdw.ca/emilycarru'),
    ],

    /*
    |--------------------------------------------------------------------------
    | UserAgreement auto-create
    |--------------------------------------------------------------------------
    |
    | Triggers that produce UserAgreement rows without anyone clicking
    | through the form / admin UI.
    |
    | `lease_end_status_labels` — when an asset is updated and its new
    | Statuslabel name appears in this list, the auto-creator emits a
    | `purchase` UserAgreement row for the assigned user (if any),
    | pulling buyout_cost from the asset's purchase_cost. Idempotent:
    | re-saving does nothing if an open purchase row already covers
    | this (user, asset) pair.
    |
    */

    'purchase_auto_create' => [
        'lease_end_status_labels' => array_filter(array_map('trim', explode(
            ',',
            env('USER_AGREEMENT_LEASE_END_STATUS_LABELS', 'Active (Lease End)')
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pickup + upgrade auto-create on faculty checkout
    |--------------------------------------------------------------------------
    |
    | When a new laptop is checked out (assigned) to a user who is
    | eligible for the faculty-program form AND already has another
    | asset whose linked contract end_date is within
    | `lease_end_within_months`, emit a `pickup` row plus an
    | `upgrade` row when the device cost exceeds `base_program_price`.
    | Toggle off via USER_AGREEMENT_PICKUP_AUTO_CREATE_ENABLED=false.
    |
    */

    'pickup_auto_create' => [
        'enabled'                 => env('USER_AGREEMENT_PICKUP_AUTO_CREATE_ENABLED', true),
        'base_program_price'      => (float) env('USER_AGREEMENT_BASE_PROGRAM_PRICE', 2383.11),
        'lease_end_within_months' => (int) env('USER_AGREEMENT_LEASE_END_WITHIN_MONTHS', 6),
        'eligibility_form_slug'   => env('USER_AGREEMENT_PICKUP_FORM_SLUG', 'faculty-program'),
    ],

];

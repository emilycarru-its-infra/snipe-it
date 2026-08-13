<?php

use App\Forms\FacultyProgram\FacultyProgramForm;

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
            'class' => FacultyProgramForm::class,
            'label_key' => 'admin/forms/faculty-program.title',
            'description_key' => 'admin/forms/faculty-program.tile_description',
            'icon' => 'fas fa-laptop',
        ],
    ],

    'faculty_program' => [
        // Where the form sends someone once their commitment is recorded.
        // Defaults to our own store: since the 2026-07-29 process change CDW's
        // hosted eStore is retired, and pointing faculty at it left the last
        // step of the program flow at a dead page. Still an env override, for
        // the case where a vendor storefront is the right destination again.
        'purchase_url' => env('USER_FORM_PURCHASE_URL'),
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
    | Buyout estimate
    |--------------------------------------------------------------------------
    |
    | What the intake form tells somebody buying out their old laptop is
    | likely to cost, before anyone asks the lessor. A firm quote is only
    | worth requesting for the people who actually want one, so the form
    | estimates: roughly a year's rent.
    |
    | On the ECI20221001 schedule that is a flat proportion of the
    | acquisition cost — 21.4% before tax, a shade over 24% with it —
    | holding to within a fifth of a percentage point across all seven item
    | types, from a Mac mini at $1,313 to a 16-inch Pro at $3,929. A lease
    | factor rather than a depreciation curve, so multiplying the capital
    | cost reproduces the lessor's own rent line instead of approximating
    | it. Tax-inclusive, because that is the figure an invoice carries.
    |
    | A real quote, once one exists, is stored per asset in `buyout_cost`
    | and outranks this. Replacing the factor with a proper depreciation
    | model is AB#4413.
    |
    */

    'buyout_estimate' => [
        'annual_rent_factor' => (float) env('USER_AGREEMENT_BUYOUT_ESTIMATE_FACTOR', 0.24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Legacy lease-custom-field → contract_asset bridge migration
    |--------------------------------------------------------------------------
    |
    | Earlier asset records carry lease info in Snipe-IT custom fields
    | (Lease Contract Name / Lease Contract ID / Lease End Date /
    | Buyout Cost) because real Contract entities didn't exist yet.
    | The `snipeit:link-assets-to-contracts` command and its API
    | counterpart walk those custom-field values and build the proper
    | contract_asset bridge so the Reconciler + every downstream report
    | reads from one source.
    |
    | Contracts the migration creates use source='manual' so the
    | tdx-to-snipe-contracts sync leaves them alone.
    |
    */

    'asset_lease_migration' => [
        'contract_name_field_name' => env('USER_AGREEMENT_LEASE_CONTRACT_NAME_FIELD', 'Lease Contract Name'),
        'contract_id_field_name' => env('USER_AGREEMENT_LEASE_CONTRACT_ID_FIELD', 'Lease Contract ID'),
        'lease_end_date_field_name' => env('USER_AGREEMENT_LEASE_END_DATE_FIELD', 'Lease End Date'),
        'contract_name_pattern' => env('USER_AGREEMENT_LEASE_CONTRACT_NAME_PATTERN', 'Devices Leases FY%'),
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
        'enabled' => env('USER_AGREEMENT_PICKUP_AUTO_CREATE_ENABLED', true),
        'base_program_price' => (float) env('USER_AGREEMENT_BASE_PROGRAM_PRICE', 2383.11),
        'lease_end_within_months' => (int) env('USER_AGREEMENT_LEASE_END_WITHIN_MONTHS', 6),
        'eligibility_form_slug' => env('USER_AGREEMENT_PICKUP_FORM_SLUG', 'faculty-program'),

        // Cutoff date — the Reconciler will only emit pickup and
        // upgrade rows for assets whose `last_checkout` is on or
        // after this date. Skips pre-system devices that already
        // had paperwork signed outside Snipe-IT. Format: YYYY-MM-DD.
        // null = no filter (legacy behaviour, reconcile everything).
        'reconcile_from' => env('USER_AGREEMENT_PICKUP_RECONCILE_FROM'),

        // Custom field that carries the asset's purpose-built buyout
        // cost (separate from purchase_cost so it can be filled in by
        // the lease admin without overwriting historical PO data).
        'buyout_cost_field_name' => env('USER_AGREEMENT_BUYOUT_COST_FIELD', 'Buyout Cost'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Signature reminders
    |--------------------------------------------------------------------------
    |
    | The snipeit:user-agreement-signature-reminders command runs
    | daily and emails users whose agreement_sent agreements are still
    | unsigned after `interval_days`, capped at `max_reminders` per
    | row so the same user is not spammed indefinitely.
    |
    */

    'signature_reminders' => [
        'enabled' => env('USER_AGREEMENT_REMINDERS_ENABLED', true),
        'interval_days' => (int) env('USER_AGREEMENT_REMINDER_INTERVAL_DAYS', 3),
        'max_reminders' => (int) env('USER_AGREEMENT_MAX_REMINDERS', 5),
    ],

];

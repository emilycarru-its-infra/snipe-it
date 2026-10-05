<?php

return [
    'title' => 'Preferences',
    'help' => 'Fiscal year, tax rates, currency and the status labels reports treat specially',
    'keywords' => 'preferences, fiscal year, tax, gst, pst, currency, status roles, status labels',
    'intro' => 'Business values reports and workflows read at runtime. Anything left at its default follows the built-in value; an override applies at once, with no deploy.',
    'default' => 'Default',
    'overridden' => 'Overridden',
    'reset' => 'Reset to default',
    'none' => 'None',
    'list_hint' => 'One per line.',
    'unknown_keys' => 'Not a preference: :keys',
    'unknown_status' => 'There is no status label called ":name".',
    'logged' => 'Preferences changed: :keys',
    'logged_reset' => 'Preferences reset to default: :keys',
    'reset_success' => ':key is back to its default.',

    'groups' => [
        'fiscal' => 'Fiscal year',
        'tax' => 'Taxes and currency',
        'status' => 'Status roles',
    ],

    'keys' => [
        'fiscal.start_month' => [
            'label' => 'Fiscal year starts in',
            'help' => 'The first month of the fiscal year. Every fiscal-year report, picker and ledger buckets dates by it.',
        ],
        'tax.gst_rate' => [
            'label' => 'GST rate',
            'help' => 'Federal sales tax as a fraction (0.05 is 5%). Prefilled on new requisitions and used to split imported invoice tax.',
        ],
        'tax.pst_rate' => [
            'label' => 'PST rate',
            'help' => 'Provincial sales tax as a fraction (0.07 is 7%). Prefilled on new requisitions and used for the PST exposure report.',
        ],
        'tax.pst_on_capital_requests' => [
            'label' => 'Charge PST on capital requests',
            'help' => 'When off, a requisition raised from the capital request carries no PST.',
        ],
        'currency.default' => [
            'label' => 'Procurement currency',
            'help' => 'Three-letter currency code stamped on lease contracts from schedule intake and on self-serve catalog items.',
        ],
        'status.decommission_lane' => [
            'label' => 'Decommission lane',
            'help' => 'Statuses a device sits on while it is being collected for return, donation or recycling. By default every status whose name starts with "Processing".',
        ],
        'status.stuck_processing' => [
            'label' => 'Stuck in processing',
            'help' => 'Statuses the dashboard counts as stuck when a device has not changed for 14 days. By default every status named "Processing …".',
        ],
        'status.attention' => [
            'label' => 'Needs attention',
            'help' => 'Statuses the dashboard counts in its damaged/missing tile.',
        ],
        'status.store_journey.ordered' => [
            'label' => 'Store journey: ordered',
            'help' => 'Status a pre-created asset waits on until its serial arrives. The first one is created if it does not exist.',
        ],
        'status.store_journey.arrived' => [
            'label' => 'Store journey: arrived',
            'help' => 'Statuses that mean an ordered device has arrived.',
        ],
        'status.store_journey.inventoried' => [
            'label' => 'Store journey: inventoried',
            'help' => 'Statuses that mean an arrived device is inventoried. Moving an asset onto one emails the requester.',
        ],
        'status.store_journey.provisioned' => [
            'label' => 'Store journey: ready for pick up',
            'help' => 'Statuses that mean a device is provisioned and ready. Moving an asset onto one emails the requester.',
        ],
        'status.off_lease' => [
            'label' => 'Off lease (kept in service)',
            'help' => 'Statuses that take a leased device out of the refresh headcount while its budget stays: bought out, or kept past end of life.',
        ],
        'status.bought_out' => [
            'label' => 'Bought out of lease',
            'help' => 'Statuses that assert the institution bought the unit out of its lease; the ownership reconciler marks those assets Purchased.',
        ],
        'status.legacy' => [
            'label' => 'Legacy fleet',
            'help' => 'Unfunded devices past their end of life. By default every status starting "Active (Legacy)".',
        ],
        'status.legacy_buyouts' => [
            'label' => 'Legacy fleet: buyouts',
            'help' => 'Bought-out devices shown beside the legacy fleet. By default every status starting "Active (Buyout".',
        ],
        'status.funded_replacement' => [
            'label' => 'Funded replacement',
            'help' => 'Statuses that put a device in the current fiscal year\'s refresh forecast regardless of its dates.',
        ],
        'leasing.buyout_completed_status' => [
            'label' => 'Buyout completed',
            'help' => 'Archived status a device moves to when its buyout completes. Defaults to the BUYOUT_COMPLETED_STATUS environment value.',
        ],
        'leasing.pickup_completed_status' => [
            'label' => 'Lease return picked up',
            'help' => 'Archived status a device moves to when the lessor picks it up. Defaults to the LEASING_PICKUP_COMPLETED_STATUS environment value.',
        ],
        'forms.purchase_auto_create.lease_end_status_labels' => [
            'label' => 'Lease end (purchase agreement)',
            'help' => 'Statuses that open a purchase agreement for the assigned user. Defaults to the USER_AGREEMENT_LEASE_END_STATUS_LABELS environment value.',
        ],
    ],
];

<?php

return [
    'title' => 'Preferences',
    'help' => 'Fiscal year, tax, status roles, thresholds, lease terms, contacts, vocabulary and job times',
    'keywords' => 'preferences, fiscal year, tax, gst, pst, currency, status roles, status labels, thresholds, lease terms, contacts, teams channels, vocabulary, schedule',
    'intro' => 'Business values reports and workflows read at runtime. Anything left at its default follows the built-in value; an override applies at once, with no deploy.',
    'default' => 'Default',
    'overridden' => 'Overridden',
    'reset' => 'Reset to default',
    'none' => 'None',
    'list_hint' => 'One per line.',
    'map_hint' => 'One per line, as name = value.',
    'map_needs_names' => 'Every line needs a name before the "=".',
    'unknown_keys' => 'Not a preference: :keys',
    'unknown_status' => 'There is no status label called ":name".',
    'logged' => 'Preferences changed: :keys',
    'logged_reset' => 'Preferences reset to default: :keys',
    'reset_success' => ':key is back to its default.',

    'groups' => [
        'fiscal' => 'Fiscal year',
        'tax' => 'Taxes and currency',
        'status' => 'Status roles',
        'contracts' => 'Contracts',
        'leasing' => 'Leasing',
        'procurement' => 'Procurement',
        'deployments' => 'Deployments',
        'reports' => 'Dashboards and reports',
        'agreements' => 'User agreements',
        'contacts' => 'Contacts and Teams channels',
        'vocabulary' => 'Names and vocabulary',
        'links' => 'Links',
        'schedule' => 'Scheduled jobs',
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
            'help' => 'Statuses the dashboard counts as stuck when a device has not changed for the "stuck in processing after" days. By default every status named "Processing …".',
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
        'fiscal.contracts_first_year' => [
            'label' => 'Contracts register starts',
            'help' => 'The first fiscal year (by its start year) the contracts picker and fiscal-year chart offer.',
        ],
        'fiscal.picker_span_years' => [
            'label' => 'Fiscal-year picker span',
            'help' => 'Years either side of the current fiscal year the deployments pickers offer.',
        ],
        'contracts.expiring_soon_days' => [
            'label' => 'Expiring soon (days)',
            'help' => 'The first expiring tile on the contracts page counts contracts ending within this many days.',
        ],
        'contracts.expiring_later_days' => [
            'label' => 'Expiring later (days)',
            'help' => 'The second expiring tile, and the default window of the Expiring soon report.',
        ],
        'contracts.stale_tdx_days' => [
            'label' => 'Stale in TDX after (days)',
            'help' => 'An active contract not updated in TDX for longer than this shows on the Stale in TDX report.',
        ],
        'contracts.renewal_alert.first_days' => [
            'label' => 'First renewal alert (days before end)',
            'help' => 'The first renewal email goes out this many days before a contract ends.',
        ],
        'contracts.renewal_alert.second_days' => [
            'label' => 'Second renewal alert (days before end)',
            'help' => 'The second renewal email goes out this many days before a contract ends.',
        ],
        'contracts.renewal_alert.tolerance_days' => [
            'label' => 'Renewal alert tolerance (days)',
            'help' => 'Each alert matches end dates this many days either side, so a late daily run misses nothing.',
        ],
        'contracts.renewal_alert.expired_days' => [
            'label' => 'Expired digest (days)',
            'help' => 'Contracts that ended within this many days go in the expired digest.',
        ],
        'leasing.buyout_request_cooldown_days' => [
            'label' => 'Buyout request cooldown (days)',
            'help' => 'How long before the same device may be sent to the lessor for a buyout quote again.',
        ],
        'leasing.term_months.lease_to_return' => [
            'label' => 'Lease to Return term (months)',
            'help' => 'A schedule with this term is filed as Lease to Return, and leases of that kind amortise over it when the register has no dates.',
        ],
        'leasing.term_months.lease_to_own' => [
            'label' => 'Lease to Own term (months)',
            'help' => 'A schedule with this term is filed as Lease to Own, and leases of that kind amortise over it when the register has no dates.',
        ],
        'leasing.extension_watch.lookahead_months' => [
            'label' => 'Extension Watch: months before end',
            'help' => 'A lease joins the Extension Watch this many months before its end date.',
        ],
        'leasing.extension_watch.lookback_months' => [
            'label' => 'Extension Watch: months after end',
            'help' => 'A lease stays on the Extension Watch this many months past its end date.',
        ],
        'leasing.extension_watch.overdue_months' => [
            'label' => 'Extension Watch: red after (months)',
            'help' => 'A lease this many months past its end is shown in red.',
        ],
        'leasing.okp_funding_accounts' => [
            'label' => 'OK to pay: funding accounts',
            'help' => 'Funding accounts whose invoices the lessor pays, so need its OK to pay. Defaults to the leasing configuration.',
        ],
        'leasing.okp_teams_channel' => [
            'label' => 'OK to pay: Teams channel',
            'help' => 'Where OK to pay cards post when Settings > Emails names no channel. Defaults to the LEASING_OKP_TEAMS_CHANNEL environment value.',
        ],
        'procurement.part_number_stale_days' => [
            'label' => 'Part numbers stale after (days)',
            'help' => 'A catalog row whose vendor part numbers have not been checked for this long is flagged when an order is sent.',
        ],
        'procurement.pipeline_item_cap' => [
            'label' => 'Pipeline card line items',
            'help' => 'Line items listed in a procurement pipeline card before it points to the full order.',
        ],
        'procurement.money_match_tolerance' => [
            'label' => 'Amount match tolerance',
            'help' => 'Dollars two amounts may differ by and still match: invoice lines against the invoice total, schedule lines against their stated total.',
        ],
        'procurement.variance_tolerance' => [
            'label' => 'Variance tolerance',
            'help' => 'Dollars an invoice or purchase order may be off by before reports call it a variance.',
        ],
        'procurement.warranty_month_options' => [
            'label' => 'Warranty options (months)',
            'help' => 'The warranty lengths offered for a store catalog item.',
        ],
        'deployments.lease_end_window_months' => [
            'label' => 'Wave eligibility window (months)',
            'help' => 'A wave warns about anyone whose lease does not end within this many months.',
        ],
        'deployments.pickup_history_days' => [
            'label' => 'Pickup history (days)',
            'help' => 'Closed lease pickups stay on the decommission lane this many days.',
        ],
        'deployments.forecast_excluded_categories' => [
            'label' => 'Categories outside the refresh forecast',
            'help' => 'Asset categories the refresh forecast never counts. Defaults to the configuration list.',
        ],
        'dashboard.renewal_prompt_days' => [
            'label' => 'Renewal prompt (days before lease end)',
            'help' => 'A person\'s dashboard starts the renewal journey this many days before their laptop lease ends.',
        ],
        'dashboard.stuck_processing_days' => [
            'label' => 'Stuck in processing after (days)',
            'help' => 'A device on a stuck-in-processing status unchanged for this long counts as stuck.',
        ],
        'reports.audit_overdue_months' => [
            'label' => 'Audit overdue after (months)',
            'help' => 'Fleet health counts a deployable asset not audited for this long as overdue.',
        ],
        'reports.printer_usage.trend_months' => [
            'label' => 'Printer usage trend (months)',
            'help' => 'Months of history in a printer\'s monthly volume chart.',
        ],
        'reports.printer_usage.recent_days' => [
            'label' => 'Printer usage recent window (days)',
            'help' => 'The window of a printer\'s recent jobs, pages and cost tiles.',
        ],
        'agreements.lease_years' => [
            'label' => 'Laptop lease length (years)',
            'help' => 'The lease length the built-in pickup agreement quotes.',
        ],
        'agreements.loan_months' => [
            'label' => 'Upgrade loan length (months)',
            'help' => 'The interest-free loan length the built-in upgrade agreement quotes.',
        ],
        'agreements.loan_installments' => [
            'label' => 'Upgrade loan instalments',
            'help' => 'How many semi-monthly instalments repay an upgrade loan; the PDF divides the amount by it.',
        ],
        'agreements.return_term_months' => [
            'label' => 'Upgrade return term (months)',
            'help' => 'The term after which the built-in upgrade agreement says the laptop is returned.',
        ],
        'forms.buyout_estimate.annual_rent_factor' => [
            'label' => 'Buyout estimate: annual rent factor',
            'help' => 'Fraction of a device\'s capital cost a year of lease costs, used to estimate a buyout before a quote exists. Defaults to the USER_AGREEMENT_BUYOUT_ESTIMATE_FACTOR environment value.',
        ],
        'forms.signature_reminders.enabled' => [
            'label' => 'Send signature reminders',
            'help' => 'Remind people of agreements sent for signature and not yet signed.',
        ],
        'forms.signature_reminders.interval_days' => [
            'label' => 'Signature reminder interval (days)',
            'help' => 'Days between reminders for an unsigned agreement.',
        ],
        'forms.signature_reminders.max_reminders' => [
            'label' => 'Signature reminders at most',
            'help' => 'Reminders sent for one agreement before they stop.',
        ],
        'forms.pickup_auto_create.enabled' => [
            'label' => 'Create pickup and upgrade agreements',
            'help' => 'Open pickup and upgrade agreements when a program laptop is checked out to someone whose old lease is ending.',
        ],
        'forms.pickup_auto_create.base_program_price' => [
            'label' => 'Program base price',
            'help' => 'What the program covers; anything above it is an upgrade the person pays. Blank leaves upgrade amounts unknown.',
        ],
        'forms.pickup_auto_create.lease_end_within_months' => [
            'label' => 'Old lease ending within (months)',
            'help' => 'A checkout opens pickup paperwork when the person\'s current laptop lease ends within this many months.',
        ],
        'forms.pickup_auto_create.eligibility_form_slug' => [
            'label' => 'Program eligibility form',
            'help' => 'Slug of the form whose members the program covers.',
        ],
        'forms.pickup_auto_create.asset_category' => [
            'label' => 'Program device category',
            'help' => 'Only assigned devices in this category get program agreements.',
        ],
        'forms.pickup_auto_create.asset_manufacturer' => [
            'label' => 'Program device manufacturer',
            'help' => 'Only devices from this manufacturer get program agreements. Blank allows any.',
        ],
        'forms.pickup_auto_create.reconcile_from' => [
            'label' => 'Reconcile checkouts from',
            'help' => 'Pickup and upgrade rows are only created for checkouts on or after this date (YYYY-MM-DD). Blank reconciles everything.',
        ],
        'contacts.device_team' => [
            'label' => 'Device team mailboxes',
            'help' => 'Copied on store and vendor orders, and sent faculty program applications, when Settings > Emails names nobody else. Defaults to the ECU_DEVICE_TEAM_EMAILS environment value.',
        ],
        'teams.channels' => [
            'label' => 'Teams channels',
            'help' => 'The channels notifications can post to, as name = label. The name must match a channel the Teams bot is in.',
        ],
        'teams.default_channel' => [
            'label' => 'Default Teams channel',
            'help' => 'Where a notification posts when it has no channel of its own. Must be one of the channels above; otherwise the first one is used.',
        ],
        'teams.asset_custom_fields' => [
            'label' => 'Asset fields on Teams cards',
            'help' => 'Custom fields shown on checkout and check-in cards, as field name = card label, in card order.',
        ],
        'store.order_reference_prefix' => [
            'label' => 'Store order reference prefix',
            'help' => 'Prefix of a store order\'s reference, which vendors echo back and pre-created assets carry as their order number. References made under the default prefix are still recognised after a change.',
        ],
        'store.category_order' => [
            'label' => 'Store category order',
            'help' => 'The order catalog categories are shown in; unlisted categories sort last.',
        ],
        'groups.shared_purchasers' => [
            'label' => 'Shared purchasers group',
            'help' => 'Members of this group may place shared orders for labs, classrooms and team spaces.',
        ],
        'groups.faculty_match' => [
            'label' => 'Faculty groups contain',
            'help' => 'A store order from a member of any group whose name contains this joins the faculty laptop program.',
        ],
        'catalog.self_serve_supplier' => [
            'label' => 'Self-serve supplier name starts',
            'help' => 'A catalog row added from a product link is assigned the supplier whose name starts with this.',
        ],
        'catalog.self_serve_source' => [
            'label' => 'Self-serve source label',
            'help' => 'The source recorded on a catalog row added from a product link.',
        ],
        'exhibits.requested_devices' => [
            'label' => 'Exhibit requested devices',
            'help' => 'Suggestions offered for an exhibit project\'s requested device.',
        ],
        'links.tdx_contract' => [
            'label' => 'TeamDynamix contract link',
            'help' => 'Link to a contract in TeamDynamix, with {id} where the TDX id goes. Blank shows TDX ids unlinked. Defaults to the TDX_CONTRACT_URL environment value.',
        ],
        'links.carrier_tracking' => [
            'label' => 'Carrier tracking links',
            'help' => 'A carrier name (matched anywhere in the shipment\'s carrier) = the tracking URL the number is appended to.',
        ],
        'catalog.apple_store_pages' => [
            'label' => 'Apple Store pages',
            'help' => 'The buy pages the weekly Apple catalog sync reads.',
        ],
        'schedule.contract_renewals' => [
            'label' => 'Contract renewal alerts',
            'help' => 'Daily time (HH:MM, 24-hour) the contract renewal alerts run.',
        ],
        'schedule.procurement_actions' => [
            'label' => 'Procurement actions digest',
            'help' => 'Weekday time (HH:MM, 24-hour) the list of orders and quotes waiting on procurement posts to Teams.',
        ],
        'schedule.user_pregen_pdfs' => [
            'label' => 'Agreement PDF pre-generation',
            'help' => 'Daily time (HH:MM) user agreement PDFs are pre-generated.',
        ],
        'schedule.signature_reminders' => [
            'label' => 'Signature reminders',
            'help' => 'Daily time (HH:MM) signature reminders are sent.',
        ],
        'schedule.user_agreements_reconcile' => [
            'label' => 'User agreement reconcile',
            'help' => 'Daily time (HH:MM) user agreements are reconciled against checkouts.',
        ],
        'schedule.link_printer_models' => [
            'label' => 'Toner and printer linking',
            'help' => 'Daily time (HH:MM) toner is linked to compatible printer models.',
        ],
        'schedule.backfill_lessors' => [
            'label' => 'Lessor backfill',
            'help' => 'Daily time (HH:MM) missing lessors are filled in on leased assets.',
        ],
        'schedule.reconcile_lease_ownership' => [
            'label' => 'Lease ownership reconcile',
            'help' => 'Daily time (HH:MM) ownership type is reconciled with buyout statuses.',
        ],
        'schedule.sync_lease_names' => [
            'label' => 'Lease name sync',
            'help' => 'Daily time (HH:MM) lease names are copied from the contracts register.',
        ],
        'schedule.reconcile_legacy_licenses' => [
            'label' => 'Legacy license sweep',
            'help' => 'Time (HH:MM) of the Monday sweep of legacy license contracts.',
        ],
        'schedule.catalog_sync_apple' => [
            'label' => 'Apple catalog sync',
            'help' => 'Time (HH:MM) of the Monday catalog sync from the Apple Store.',
        ],
        'schedule.okay_to_pay_minutes' => [
            'label' => 'OK to pay every (minutes)',
            'help' => 'How often the OK to pay job runs, in minutes (1–59).',
        ],
    ],
];

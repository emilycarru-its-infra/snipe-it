<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Lease buyout requests
    |--------------------------------------------------------------------------
    |
    | When an admin clicks "Request Buyout" on a leased asset, Snipe emails the
    | asset's lessor (the Supplier record in the lessor role) asking for a
    | buyout quote. These addresses are CC'd / used as Reply-To so replies land
    | with the device team rather than the noreply from-address. The CC list is
    | comma-separated; Reply-To must be a single address.
    |
    */

    'buyout_request_cc' => env('BUYOUT_REQUEST_CC', 'devicesadmins@ecuad.ca,rdatta@ecuad.ca'),

    'buyout_request_reply_to' => env('BUYOUT_REQUEST_REPLY_TO', 'devicesadmins@ecuad.ca'),

    /*
    |--------------------------------------------------------------------------
    | Payroll deduction on an approved buyout
    |--------------------------------------------------------------------------
    |
    | When the buyer approves, payroll is mailed the quote, the device and the
    | amount to deduct. Both lists are comma-separated and set per environment;
    | a Settings → Emails override wins over either. With no recipients the
    | notice is skipped rather than sent nowhere.
    |
    */

    'buyout_payroll_to' => env('BUYOUT_PAYROLL_TO', ''),

    'buyout_payroll_cc' => env('BUYOUT_PAYROLL_CC', env('BUYOUT_REQUEST_REPLY_TO', 'devicesadmins@ecuad.ca')),

    /*
    |--------------------------------------------------------------------------
    | Where a completed buyout lands the device
    |--------------------------------------------------------------------------
    |
    | Completing a buyout parks the asset on this status label, which must be
    | an archived one: that is the signal the management systems read to drop
    | the device, and what keeps a sold machine out of the lease-return pool.
    | Resolved by name, because status labels are data rather than schema.
    |
    | "Purchased" here is the existing archived status meaning *purchased by
    | faculty* — the person bought it. It is not the `ownership_type` value of
    | the same name, which means ECU bought the unit off its lease and still
    | owns it. See App\Services\Leasing\BuyoutTracker.
    |
    */

    'buyout_completed_status' => env('BUYOUT_COMPLETED_STATUS', 'Purchased'),

    /*
    |--------------------------------------------------------------------------
    | Lease return pickups
    |--------------------------------------------------------------------------
    |
    | "Request pickup" on the decommissioning lane mails a lessor the devices
    | waiting to go back. It is addressed like the buyout request: To the
    | lessor's own contacts, Cc this list (a Settings → Emails override wins).
    |
    | The site details are the standing answers a lessor's end-of-lease
    | partner asks for before booking a truck — where the equipment waits,
    | receiving hours, site contact, dock and elevator access, truck limits.
    | One value, with \n between lines; empty leaves the section out.
    |
    | A picked-up device lands on the completed status, which must be an
    | archived one. Resolved by name, like the buyout status below.
    |
    */

    'pickup_request_cc' => env('LEASING_PICKUP_REQUEST_CC', env('BUYOUT_REQUEST_REPLY_TO', '')),

    'pickup_request_reply_to' => env('LEASING_PICKUP_REQUEST_REPLY_TO', env('BUYOUT_REQUEST_REPLY_TO', env('MAIL_FROM_ADDR'))),

    'pickup_site_details' => env('LEASING_PICKUP_SITE_DETAILS', ''),

    'pickup_completed_status' => env('LEASING_PICKUP_COMPLETED_STATUS', 'Returned Lease End'),

    /*
    |--------------------------------------------------------------------------
    | Extra buyout recipients live on the lessor, not here
    |--------------------------------------------------------------------------
    |
    | A lessor fielding more than one rep (CCA Financial has a second) lists the
    | extras in `lease_emails` on that Supplier record — Suppliers → edit. There
    | is deliberately no global equivalent: a buyout request names the contract
    | number, asset tag and serial, so one existed only long enough to address a
    | CSI Leasing request to CCA Financial as well.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | OK to pay — the lessor's approval-to-pay, sent before they ask
    |--------------------------------------------------------------------------
    |
    | A vendor bills the lessor for equipment on a lease schedule, and the
    | lessor will not remit until we reply "OK to pay". App\Services\Leasing\
    | OkayToPay sends that reply as each lease invoice lands and matches its
    | order, after a review window announced in the Procurement Teams channel.
    |
    |   off     nothing is queued or sent
    |   review  invoices are queued and announced, but only sent once someone
    |           approves them in the Invoice Approval Queue
    |   auto    as review, and a queued invoice also sends when the window ends
    |
    | Every value below is only the deployment default: Settings → Emails →
    | OK to pay overrides each one, so changing who it goes to, who it is from
    | or whether it sends at all is a setting, not a deploy. With no recipient
    | nothing is queued.
    |
    */
    'okp_mode' => env('LEASING_OKP_MODE', 'off'),
    // Hours a matching invoice waits before it sends. 0 sends on the next
    // scheduled pass and posts a card only for invoices that are held.
    'okp_review_hours' => (int) env('LEASING_OKP_REVIEW_HOURS', 0),
    // Teams channel the queued / reminder / held / sent cards post to.
    'okp_teams_channel' => env('LEASING_OKP_TEAMS_CHANNEL', 'Procurement'),
    // Only invoices dated on or after this are considered. A vendor re-sending
    // old invoices (to close a gap in the webhook) must not tell the lessor to
    // pay for equipment it paid for months ago. Unset, nothing is considered.
    'okp_invoices_from' => env('LEASING_OKP_INVOICES_FROM', ''),
    'okp_to' => env('LEASING_OKP_TO', ''),
    'okp_cc' => env('LEASING_OKP_CC', ''),
    'okp_from_address' => env('LEASING_OKP_FROM_ADDRESS', ''),
    'okp_from_name' => env('LEASING_OKP_FROM_NAME', ''),
    // Funding accounts whose invoices the lessor pays, so need its sign-off.
    'okp_funding_accounts' => ['lease_admin', 'lease_curriculum'],
    // Which lessor the OK to pay is for, by Supplier name. Its invoices are
    // the only ones considered, and every outside recipient must be one of
    // that lessor's own addresses. Unset, nothing is considered.
    'okp_lessor' => env('LEASING_OKP_LESSOR', ''),

    /*
    |--------------------------------------------------------------------------
    | Opening the next lease schedules — the quarterly email to the lessor
    |--------------------------------------------------------------------------
    |
    | The email itself is composed and sent by an automation outside this app,
    | which asks this app who it goes to (GET /api/v1/settings/emails/{key}).
    | These are the deployment defaults; Settings → Emails overrides each one.
    | A value left empty everywhere leaves the automation on its own default.
    |
    */
    'schedule_init_to' => env('LEASING_SCHEDULE_INIT_TO', ''),
    'schedule_init_cc' => env('LEASING_SCHEDULE_INIT_CC', ''),
    'schedule_init_reply_to' => env('LEASING_SCHEDULE_INIT_REPLY_TO', ''),
    'schedule_init_headsup_to' => env('LEASING_SCHEDULE_INIT_HEADSUP_TO', ''),
    'schedule_init_spend' => env('LEASING_SCHEDULE_INIT_SPEND', ''),
    // The master agreement the quarterly schedules are opened under; its
    // lessor is the only outside party the email may go to.
    'schedule_init_master' => env('LEASING_SCHEDULE_INIT_MASTER', ''),

    /*
    |--------------------------------------------------------------------------
    | The university's own mail domains
    |--------------------------------------------------------------------------
    |
    | Every email to a lessor may only go to these and to that lessor's own
    | domains (App\Services\Leasing\LessorGuard). The domain of the app's
    | From address is always included; list any others, comma-separated.
    |
    */
    'internal_domains' => env('LEASING_INTERNAL_DOMAINS', ''),

    /*
    |--------------------------------------------------------------------------
    | The lessor behind the CSI mirror
    |--------------------------------------------------------------------------
    |
    | The CSI reconciliation and schedule reports cover one lessor's leases:
    | the Supplier with this name. Its contract prefixes (Suppliers → edit)
    | decide which contracts those are. Unset, they cover none.
    |
    */
    'csi_lessor' => env('LEASING_CSI_LESSOR', ''),
];

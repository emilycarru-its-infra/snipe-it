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

];

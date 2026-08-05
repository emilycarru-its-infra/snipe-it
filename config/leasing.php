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
    | Extra buyout recipients (added on top of the lessor's own email)
    |--------------------------------------------------------------------------
    |
    | A Supplier record carries a single contact email, but some lessors field
    | more than one rep who must be on every buyout request (CCA Financial has a
    | second rep, Ali Asghar). This is the seeded default; admins override it in
    | Settings → Emails → "Lease buyout request" (the CMS Recipients field wins
    | when set). Comma-separated. `CCA_BUYOUT_RECIPIENTS` is kept as a legacy
    | env alias.
    |
    */

    'buyout_request_extra_recipients' => env(
        'BUYOUT_REQUEST_EXTRA_RECIPIENTS',
        env('CCA_BUYOUT_RECIPIENTS', 'aasghar@ccafinancial.com')
    ),

];

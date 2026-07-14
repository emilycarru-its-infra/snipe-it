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
    | with the device team rather than the noreply from-address.
    |
    */

    'buyout_request_cc' => env('BUYOUT_REQUEST_CC', 'devicesadmins@ecuad.ca'),

    'buyout_request_reply_to' => env('BUYOUT_REQUEST_REPLY_TO', env('BUYOUT_REQUEST_CC', 'devicesadmins@ecuad.ca')),

    /*
    |--------------------------------------------------------------------------
    | Additional lessor recipients (always To'd)
    |--------------------------------------------------------------------------
    |
    | Some lessors have more than one rep who must be on every buyout request,
    | but a Supplier record only carries a single contact email. Map extra
    | addresses here, keyed by the exact lessor Supplier name; they are merged
    | into the To line alongside the supplier's own email. CCA Financial fields
    | two reps — the supplier email plus Ali Asghar.
    |
    */

    'additional_recipients' => [
        'CCA Financial' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('CCA_BUYOUT_RECIPIENTS', 'aasghar@ccafinancial.com'))
        ))),
    ],

];

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

];

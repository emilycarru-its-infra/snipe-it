<?php

namespace App\Events;

use App\Models\CheckoutAcceptance;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CheckoutDeclined
{
    use Dispatchable, SerializesModels;

    public CheckoutAcceptance $acceptance;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(CheckoutAcceptance $acceptance)
    {
        $this->acceptance = $acceptance;
    }
}

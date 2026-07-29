<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What CDW needs from us, and what CDW sends back.
 *
 * Since the 2026-07-29 process change the reseller's own storefront is out
 * of the loop: orders are placed here and reach CDW as one order request
 * per batch. That makes this system the only place an order exists before
 * it is a quote, so it has to carry the facts CDW's desk cannot infer.
 *
 * Two of those are per product. Warranty term is not a spec the requester
 * chooses — it is what we buy on that model, and it differs by product
 * (three years on some, four on others), so the reseller has to be told
 * per line rather than once per order. The bundle URL is CDW's own record
 * for a configure-to-order build, which is the fastest way for their desk
 * to confirm a Z-code is the build we mean.
 *
 * The rest are per order. Which account an order is charged to decides
 * which blanket purchase order the reseller places it against, and there
 * is no default that is safe to guess: a lease and an operating purchase
 * are different documents. The CSI schedule is the other half of that —
 * two are open at any time (four-year lease-to-return, five-year
 * lease-to-own) and they roll over quarterly, so the reference is a fact
 * about *when* the order was placed and has to be stored with it, not
 * looked up later.
 *
 * The quote fields close the loop the other way. CDW answers an order
 * request with a quote carrying its own number, its own total and an
 * expiry, and nothing is actually ordered until we sign that off. Without
 * somewhere to put it that approval lives only in a mailbox.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            // Months, not years: Apple sells AppleCare in years but the
            // Lenovo and Microsoft terms we buy are quoted in months, and
            // one unit that divides cleanly beats two that don't.
            $table->unsignedSmallInteger('warranty_months')->nullable()->after('extras');

            // CDW's managed-list entry for a CTO build.
            $table->string('bundle_url')->nullable()->after('warranty_months');
        });

        Schema::table('store_orders', function (Blueprint $table) {
            $table->string('funding_account', 32)->nullable()->index()->after('program');
            $table->string('lease_schedule', 32)->nullable()->after('funding_account');

            $table->string('quote_number')->nullable()->index()->after('vendor_sent_at');
            $table->decimal('quote_total', 15, 4)->nullable()->after('quote_number');
            $table->date('quote_expires_at')->nullable()->after('quote_total');
            $table->timestamp('quote_received_at')->nullable()->after('quote_expires_at');

            // Distinct from quote_received_at: the quote arriving is CDW's
            // move, confirming it is ours, and only the second one means
            // the order is placed.
            $table->timestamp('confirmed_at')->nullable()->after('quote_received_at');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropColumn(['warranty_months', 'bundle_url']);
        });

        Schema::table('store_orders', function (Blueprint $table) {
            $table->dropColumn([
                'funding_account',
                'lease_schedule',
                'quote_number',
                'quote_total',
                'quote_expires_at',
                'quote_received_at',
                'confirmed_at',
            ]);
        });
    }
};

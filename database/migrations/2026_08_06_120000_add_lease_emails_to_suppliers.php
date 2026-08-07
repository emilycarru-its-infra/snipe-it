<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move the extra lease-buyout recipients off the global config key and onto the
 * lessor they actually belong to.
 *
 * `leasing.buyout_request_extra_recipients` was one list added to the To of every
 * buyout request regardless of which lessor held the lease, so a CSI Leasing
 * request was also addressed to CCA Financial's second rep — each lessor seeing
 * the other's contract number, asset tag and serial. `lease_emails` is the
 * per-supplier sibling of `order_emails`: extra addresses for lease
 * correspondence with that lessor and nobody else.
 */
return new class extends Migration
{
    /** The old global default, which was only ever CCA Financial's second rep. */
    private const LEGACY_EXTRA_RECIPIENTS = 'aasghar@ccafinancial.com';

    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('lease_emails')->nullable()->after('order_emails');
        });

        // Carry the legacy default onto CCA Financial so its second rep keeps
        // receiving that lessor's own buyout requests — and only those. The
        // config key goes away in this same change, so the value is inlined
        // rather than read back from a setting that no longer exists; neither
        // BUYOUT_REQUEST_EXTRA_RECIPIENTS nor CCA_BUYOUT_RECIPIENTS was ever
        // set in any environment, so the seeded default is what was in effect.
        DB::table('suppliers')
            ->where('name', 'CCA Financial')
            ->whereNull('lease_emails')
            ->update(['lease_emails' => self::LEGACY_EXTRA_RECIPIENTS]);

        // A Recipients override on the buyout email was a global To list on a
        // lessor-specific email — the same cross-addressing defect from the CMS
        // side. The field is no longer offered or read; clear any stored value
        // so it cannot be mistaken for something still in effect.
        if (Schema::hasTable('email_templates')) {
            DB::table('email_templates')
                ->where('key', 'request.asset_buyout')
                ->update(['recipients' => null]);
        }
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('lease_emails');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddEulaOverrideToCheckoutAcceptances extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('checkout_acceptances', 'eula_text_override')) {
            Schema::table('checkout_acceptances', function (Blueprint $table) {
                // Snipe's native acceptance flow pulls its EULA from the
                // asset category. The Faculty Laptop Program needs three
                // distinct EULA bodies (pickup, paid upgrade, lease-end
                // buyout) against assets that share one category, so
                // this column lets a specific acceptance override the
                // category EULA without affecting other checkouts.
                $table->text('eula_text_override')->nullable();
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('checkout_acceptances', 'eula_text_override')) {
            Schema::table('checkout_acceptances', function (Blueprint $table) {
                $table->dropColumn('eula_text_override');
            });
        }
    }
}

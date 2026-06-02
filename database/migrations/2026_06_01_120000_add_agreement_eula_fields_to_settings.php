<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB-backed copy for the three User Agreement bodies (pickup / upgrade /
 * purchase) plus their PDF heading titles, so an admin can edit the legal
 * text under Settings → Agreements without a code deploy. Left null here;
 * UserAgreement::resolveEulaText() falls back to the
 * admin/user-agreements/eula.php lang strings whenever a column is blank,
 * so existing records keep rendering the current copy until someone
 * overrides it in the UI.
 */
return new class extends Migration
{
    private array $columns = [
        'agreement_pickup_title',
        'agreement_pickup_body',
        'agreement_upgrade_title',
        'agreement_upgrade_body',
        'agreement_purchase_title',
        'agreement_purchase_body',
    ];

    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            foreach ($this->columns as $column) {
                $table->longText($column)->nullable()->default(null);
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn($this->columns);
        });
    }
};

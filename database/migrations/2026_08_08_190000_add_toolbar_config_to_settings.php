<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GUI-editable toolbar: the stored order/visibility of the top-level tabs
 * (see App\Helpers\ToolbarConfig). Null means the built-in defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->text('toolbar_config')->nullable()->after('default_currency');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('toolbar_config');
        });
    }
};

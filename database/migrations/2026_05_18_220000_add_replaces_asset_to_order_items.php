<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddReplacesAssetToOrderItems extends Migration
{
    public function up()
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'replaces_asset_id')) {
                $table->unsignedBigInteger('replaces_asset_id')->nullable()->index()->after('item_id');
            }
        });
    }

    public function down()
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'replaces_asset_id')) {
                $table->dropColumn('replaces_asset_id');
            }
        });
    }
}

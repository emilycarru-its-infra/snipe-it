<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPlanningToOrders extends Migration
{
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'is_planned')) {
                $table->boolean('is_planned')->default(false)->index()->after('status');
            }
            if (! Schema::hasColumn('orders', 'fiscal_year')) {
                $table->string('fiscal_year')->nullable()->after('is_planned');
            }
        });
    }

    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'fiscal_year')) {
                $table->dropColumn('fiscal_year');
            }
            if (Schema::hasColumn('orders', 'is_planned')) {
                $table->dropColumn('is_planned');
            }
        });
    }
}

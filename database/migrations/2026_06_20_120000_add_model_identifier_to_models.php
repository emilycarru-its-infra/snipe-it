<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('models', function (Blueprint $table) {
            if (! Schema::hasColumn('models', 'model_identifier')) {
                $table->string('model_identifier')->nullable()->after('model_number');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('models', function (Blueprint $table) {
            if (Schema::hasColumn('models', 'model_identifier')) {
                $table->dropColumn('model_identifier');
            }
        });
    }
};

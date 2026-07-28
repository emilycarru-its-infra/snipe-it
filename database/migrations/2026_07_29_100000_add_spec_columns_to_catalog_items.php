<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured product attributes on catalog rows, parsed out of the price
 * list's display names (and refreshed from Apple's own store data). The
 * storefront's configurator filters on these instead of string names.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->string('family')->nullable()->index()->after('subcategory');
            $table->string('screen_size', 16)->nullable()->after('family');
            $table->string('chip', 32)->nullable()->after('screen_size');
            $table->string('spec_cpu', 64)->nullable()->after('chip');
            $table->string('spec_gpu', 64)->nullable()->after('spec_cpu');
            $table->string('spec_npu', 64)->nullable()->after('spec_gpu');
            $table->unsignedSmallInteger('ram_gb')->nullable()->after('spec_npu');
            $table->string('storage', 16)->nullable()->after('ram_gb');
            $table->string('color', 32)->nullable()->after('storage');
            $table->string('display_finish', 16)->nullable()->after('color');
            $table->string('extras')->nullable()->after('display_finish');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropColumn([
                'family', 'screen_size', 'chip', 'spec_cpu', 'spec_gpu', 'spec_npu',
                'ram_gb', 'storage', 'color', 'display_finish', 'extras',
            ]);
        });
    }
};

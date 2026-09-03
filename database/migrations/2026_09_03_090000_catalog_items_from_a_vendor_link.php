<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adding to the catalog from a vendor product link.
 *
 * The catalog is curated, and self-serve rows are the deliberate exception:
 * somebody with store access finds a one-off the catalog does not carry and
 * adds it themselves rather than asking. So a row remembers where it came
 * from, and every attempt is recorded — including the ones that found
 * nothing, because what people ask for and cannot get is the more useful
 * half of that record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            if (! Schema::hasColumn('catalog_items', 'source_url')) {
                $table->string('source_url', 1024)->nullable()->after('source');
            }

            // Curated rows reach the store through a model or the Accessories
            // category. A self-serve row has neither guaranteed — nobody is
            // going to attach an asset model to an HDMI switch — so it says
            // so, and the store scope lets it through on that alone.
            if (! Schema::hasColumn('catalog_items', 'self_serve')) {
                $table->boolean('self_serve')->default(false)->after('show_in_store');
            }
        });

        if (! Schema::hasTable('catalog_item_requests')) {
            Schema::create('catalog_item_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('created_by')->nullable()->index();
                $table->string('url', 1024);
                $table->string('vendor_sku', 191)->nullable()->index();
                $table->string('name', 191)->nullable();
                $table->unsignedInteger('catalog_item_id')->nullable()->index();
                // created | duplicate | failed
                $table->string('outcome', 32)->index();
                $table->string('error', 512)->nullable();
                $table->json('payload')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_item_requests');

        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropColumn(['source_url', 'self_serve']);
        });
    }
};

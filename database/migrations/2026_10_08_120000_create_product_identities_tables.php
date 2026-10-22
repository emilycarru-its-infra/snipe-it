<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product identity: the join key between the application names an endpoint
 * agent observes and the licenses we pay for.
 *
 * Windows reports a spray of executables and components per product, macOS
 * reports bundle identifiers, and neither rolls up to "Autodesk Maya" as a
 * licensable product. A product identity is that rollup. It carries the
 * canonical product name (the procurement-side vocabulary), its publisher,
 * and per-platform alias patterns that never mention a version, so a version
 * bump does not orphan the history.
 *
 * Licenses link to identities many-to-many: one license can cover several
 * products (a bundle), and one product can be covered by different licenses
 * in different fiscal years. A link with no fiscal year applies to every
 * year.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_identities', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('publisher')->nullable();
            // The product name as procurement spells it, when that differs
            // from the display name: the third leg of the join.
            $table->string('procurement_name')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unsignedInteger('created_by')->nullable();
            $table->index('name');
            $table->engine = 'InnoDB';
        });

        Schema::create('product_identity_aliases', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('product_identity_id');
            // any | macos | windows
            $table->string('platform', 16)->default('any');
            // exact | prefix | regex
            $table->string('match_type', 16)->default('exact');
            $table->string('pattern');
            $table->timestamps();

            $table->foreign('product_identity_id')
                ->references('id')->on('product_identities')
                ->cascadeOnDelete();
            $table->index(['platform', 'match_type']);
            $table->engine = 'InnoDB';
        });

        Schema::create('license_product_identity', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('license_id');
            $table->unsignedBigInteger('product_identity_id');
            // FY2026-27 shape; null means the link holds for every year.
            $table->string('fiscal_year', 16)->nullable();
            $table->timestamps();

            $table->foreign('license_id')
                ->references('id')->on('licenses')
                ->cascadeOnDelete();
            $table->foreign('product_identity_id')
                ->references('id')->on('product_identities')
                ->cascadeOnDelete();
            $table->unique(['license_id', 'product_identity_id', 'fiscal_year'], 'license_product_identity_unique');
            $table->engine = 'InnoDB';
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_product_identity');
        Schema::dropIfExists('product_identity_aliases');
        Schema::dropIfExists('product_identities');
    }
};

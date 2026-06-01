<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PR-C cleanup. The `contract_license` M:N pivot is now superseded by
 * the 1:N FK `licenses.contract_id` introduced in PR-B. Dev and prod
 * both have an empty pivot (it was never populated), so this drop is
 * a no-op data-wise; it just removes a dead table and its constraints.
 *
 * Reversal: recreates the pivot in the original shape so PR-B's
 * relationship would be reachable if anyone ever down-migrated. The
 * data would still be empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('contract_license');
    }

    public function down(): void
    {
        if (Schema::hasTable('contract_license')) {
            return;
        }

        Schema::create('contract_license', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contract_id');
            $table->unsignedBigInteger('license_id');
            $table->unsignedInteger('seats_covered')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->string('notes', 1000)->nullable();
            $table->timestamps();

            $table->index('contract_id');
            $table->index('license_id');
            $table->unique(['contract_id', 'license_id']);
        });
    }
};

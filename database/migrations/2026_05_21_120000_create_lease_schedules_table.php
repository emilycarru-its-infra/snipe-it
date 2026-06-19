<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLeaseSchedulesTable extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('lease_schedules')) {
            Schema::create('lease_schedules', function (Blueprint $table) {
                $table->id();
                // The schedule reference the lessor uses on the draft —
                // e.g. "301452-007" (CSI) or "ECI20240801-1" (CCA Financial /
                // CCA Financial). Stored as a string and matched against
                // the Lease Contract ID custom field on assets; no FK,
                // mirroring the existing data flow.
                $table->string('schedule_ref')->index();
                $table->string('lessor')->nullable();
                $table->string('lease_type')->nullable();
                $table->unsignedSmallInteger('term_months')->nullable();
                $table->date('received_at')->nullable();
                $table->decimal('expected_acquisition_cost', 15, 4)->nullable();
                $table->unsignedInteger('expected_asset_count')->nullable();
                $table->string('usage_tag')->nullable();
                // Minimal lifecycle: draft → awaiting_signature → signed
                // → active. cancelled is a terminal escape hatch for
                // schedules the lessor pulls back.
                $table->string('lifecycle_stage')->default('draft')->index();
                $table->timestamp('signed_at')->nullable();
                $table->unsignedInteger('signed_by')->nullable();
                $table->boolean('vendor_on_hold')->default(false)->index();
                $table->string('annexure_a_path')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->engine = 'InnoDB';
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('lease_schedules');
    }
}

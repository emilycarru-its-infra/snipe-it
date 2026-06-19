<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local mirror of CSI (MyCSI) lease data. The csi-poller function fetches
 * each CSI endpoint and POSTs normalized batches to /api/v1/csi/snapshot,
 * which upserts these tables. The procurement reconciliation engine reads
 * the mirror and diffs it against Snipe's own assets / orders / invoices —
 * Snipe stays the lease source of truth; CSI is authoritative only for
 * commencement dates and invoice amounts.
 *
 * last_seen_at marks rows touched in the latest sync so a stale row (no
 * longer returned by CSI) can be detected without deleting history.
 */
class CreateCsiMirrorTables extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('csi_leases')) {
            Schema::create('csi_leases', function (Blueprint $table) {
                $table->id();
                $table->string('lease_number')->unique();
                $table->date('term_start_date')->nullable();
                $table->date('term_end_date')->nullable();
                $table->json('raw')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();
                $table->engine = 'InnoDB';
            });
        }

        if (! Schema::hasTable('csi_schedules')) {
            Schema::create('csi_schedules', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('csi_schedule_id')->nullable();
                $table->string('schedule_name')->unique();
                $table->string('lease_number')->nullable()->index();
                $table->date('term_start_date')->nullable();
                $table->date('term_end_date')->nullable();
                $table->decimal('rent', 15, 2)->nullable();
                $table->decimal('tax', 15, 2)->nullable();
                $table->string('currency', 8)->nullable();
                $table->string('payment_frequency')->nullable();
                $table->timestamp('csi_last_updated')->nullable();
                $table->json('raw')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();
                $table->engine = 'InnoDB';
            });
        }

        if (! Schema::hasTable('csi_assets')) {
            Schema::create('csi_assets', function (Blueprint $table) {
                $table->id();
                $table->string('serial')->index();
                $table->string('lease_number')->nullable()->index();
                $table->string('schedule_name')->nullable()->index();
                $table->string('manufacturer')->nullable();
                $table->string('model')->nullable();
                $table->json('raw')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();
                $table->unique(['serial', 'schedule_name'], 'csi_assets_serial_schedule_unique');
                $table->engine = 'InnoDB';
            });
        }

        if (! Schema::hasTable('csi_inprocess_assets')) {
            Schema::create('csi_inprocess_assets', function (Blueprint $table) {
                $table->id();
                $table->string('serial')->unique();
                $table->string('lease_number')->nullable()->index();
                $table->string('schedule_name')->nullable();
                $table->string('order_number')->nullable();
                $table->string('po_number')->nullable();
                $table->string('manufacturer')->nullable();
                $table->string('model')->nullable();
                $table->json('raw')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();
                $table->engine = 'InnoDB';
            });
        }

        if (! Schema::hasTable('csi_invoices')) {
            Schema::create('csi_invoices', function (Blueprint $table) {
                $table->id();
                $table->string('csi_invoice_number')->unique();
                $table->string('lease_number')->nullable()->index();
                $table->string('schedule_name')->nullable()->index();
                $table->date('invoice_date')->nullable();
                $table->decimal('amount', 15, 2)->nullable();
                $table->string('currency', 8)->nullable();
                $table->unsignedBigInteger('matched_order_invoice_id')->nullable()->index();
                $table->json('raw')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();
                $table->engine = 'InnoDB';
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('csi_invoices');
        Schema::dropIfExists('csi_inprocess_assets');
        Schema::dropIfExists('csi_assets');
        Schema::dropIfExists('csi_schedules');
        Schema::dropIfExists('csi_leases');
    }
}

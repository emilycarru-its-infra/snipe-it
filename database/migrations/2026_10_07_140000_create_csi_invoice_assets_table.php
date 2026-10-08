<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-device lines on each CSI rental invoice, mirrored from CSI's
 * Invoice/Assets endpoint. csi_invoices holds only the invoice total; these
 * rows carry what each device was billed for the period — rent, period rent
 * and the tax split — so lease billing can be reconciled and charged back
 * per device rather than per schedule.
 *
 * One row per device per invoice. csi_asset_id is CSI's own asset id, which
 * keeps unserialized financed lines (rack kits carry serial "N/A") from
 * collapsing onto one another on the same invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('csi_invoice_assets')) {
            return;
        }

        Schema::create('csi_invoice_assets', function (Blueprint $table) {
            $table->id();
            $table->string('csi_invoice_number')->index();
            $table->unsignedBigInteger('csi_asset_id')->nullable();
            $table->string('serial')->nullable()->index();
            $table->string('lease_number')->nullable()->index();
            $table->string('schedule_name')->nullable()->index();
            $table->date('period_start_date')->nullable();
            $table->date('period_end_date')->nullable();
            $table->decimal('rent', 15, 2)->nullable();
            $table->decimal('period_rent', 15, 2)->nullable();
            $table->decimal('tax_gst', 15, 2)->nullable();
            $table->decimal('tax_pst', 15, 2)->nullable();
            $table->decimal('tax_other', 15, 2)->nullable();
            $table->decimal('tax_rate', 8, 4)->nullable();
            $table->string('currency', 8)->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['csi_invoice_number', 'csi_asset_id', 'serial'], 'csi_invoice_assets_line_unique');
            $table->engine = 'InnoDB';
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csi_invoice_assets');
    }
};

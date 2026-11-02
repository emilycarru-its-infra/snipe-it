<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('okay_to_pay_legacy_settlements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id')->unique();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->string('invoice_number');
            $table->string('invoice_key', 64)->nullable()->index();
            $table->string('reason', 24);
            $table->json('snapshot');
            $table->timestamp('created_at');
        });
        // Retain every known historical settlement, including duplicate groups.
        // These are holds, never reconstructed acceptance or outgoing messages.
        DB::table('order_invoices')->leftJoin('orders', 'orders.id', '=', 'order_invoices.order_id')
            ->where(function ($query) {
                $query->where(fn ($legacy) => $legacy->where('order_invoices.approval_status', 'approved')
                    ->where(fn ($unsigned) => $unsigned->whereNull('order_invoices.okp_status')->orWhere('order_invoices.okp_status', '')))
                    ->orWhereNotNull('order_invoices.okp_status')->orWhereNotNull('order_invoices.okp_sent_at');
            })
            ->select('order_invoices.*', 'orders.supplier_id as original_supplier_id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $supplierId = $row->original_supplier_id;
                    $number = (string) $row->invoice_number;
                    $key = $supplierId && trim($number) !== ''
                        ? hash('sha256', $supplierId."\n".mb_strtolower(trim($number))) : null;
                    DB::table('okay_to_pay_legacy_settlements')->insert([
                        'invoice_id' => $row->id,
                        'supplier_id' => $supplierId,
                        'invoice_number' => $number,
                        'invoice_key' => $key,
                        'reason' => $row->okp_status === 'sent' || $row->okp_sent_at
                            ? 'previously_sent'
                            : ($row->approval_status === 'approved' && ! $row->okp_status ? 'legacy_approved' : 'legacy_delivery_unknown'),
                        'snapshot' => json_encode(array_intersect_key((array) $row, array_flip([
                            'order_id', 'invoice_number', 'invoice_date', 'approval_status', 'approved_at',
                            'approved_by', 'okp_status', 'okp_sent_at', 'okp_sent_to',
                        ])), JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                    ]);
                }
            }, 'order_invoices.id', 'id');
        Schema::create('okay_to_pay_acceptances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id')->index();
            $table->string('decision', 10);
            $table->string('fingerprint', 64);
            $table->json('snapshot');
            $table->unsignedInteger('actor_id');
            $table->string('actor_name', 191);
            $table->string('receipt_reference', 1000)->nullable();
            $table->string('acceptance_reference', 1000)->nullable();
            $table->timestamp('created_at');
        });
        Schema::create('okay_to_pay_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id')->unique();
            $table->string('invoice_key', 64)->unique();
            $table->unsignedBigInteger('acceptance_id');
            $table->string('fingerprint', 64);
            $table->string('status', 20);
            $table->text('recipients');
            $table->text('cc');
            $table->longText('payload');
            $table->string('payload_digest', 64);
            $table->string('routing_digest', 64);
            $table->timestamp('claimed_at');
            $table->timestamp('completed_at')->nullable();
            $table->text('error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('okay_to_pay_deliveries');
        Schema::dropIfExists('okay_to_pay_acceptances');
        Schema::dropIfExists('okay_to_pay_legacy_settlements');
    }
};

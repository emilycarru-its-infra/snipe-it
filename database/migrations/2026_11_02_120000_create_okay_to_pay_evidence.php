<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
    }
};

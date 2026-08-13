<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A device leaving the fleet by purchase rather than by pickup.
 *
 * The "Request Buyout" button already mails the lessor and logs the send to
 * the asset's timeline, but everything after that — the quote, the split
 * between what the buyer pays and what ECU absorbs, the approval, the
 * lessor's invoice, the payment — lived in one long mail thread. This table
 * is that stretch, so the decommissioning lane can show a buyout in flight
 * beside the devices being collected, and so "did we ever take payment?" is
 * a column rather than a question asked into a reply-all.
 *
 * Quotes get their own table because they supersede: a lessor may re-price
 * the same device days later, and the superseded figure has to stay
 * readable next to the one that replaced it. The live quote is mirrored
 * onto the parent row so the lane can sort and total without a join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_buyouts', function (Blueprint $table) {
            $table->id();
            // Nullable, because HR opens these by person: "X is retiring, what
            // would their laptop cost?" arrives before anyone has pinned down
            // which device that is. A row with a buyer and no asset is an
            // honest record of a case in progress, not a broken one.
            $table->unsignedInteger('asset_id')->nullable()->index();
            $table->unsignedInteger('lessor_id')->nullable()->index();

            // Who is buying the device. Usually the person it is checked out
            // to, who is on their way out the door — that departure is what
            // starts almost every one of these — but it is stored rather than
            // derived, because checkin happens before the money settles.
            $table->unsignedInteger('buyer_id')->nullable()->index();
            $table->unsignedInteger('requested_by')->nullable();

            $table->string('status', 24)->default('requested')->index();
            $table->timestamp('requested_at')->nullable();

            // The live quote, mirrored from the newest asset_buyout_quotes row.
            $table->decimal('quote_amount', 12, 2)->nullable();
            $table->decimal('remaining_rent', 12, 2)->nullable();
            $table->decimal('quote_total', 12, 2)->nullable();
            $table->date('quoted_at')->nullable();

            // What the buyer is asked for, and what ECU absorbs. Defaulted to
            // the whole total on the first quote and overridden per case —
            // there is no written rule yet, only precedent.
            $table->decimal('buyer_amount', 12, 2)->nullable();
            $table->decimal('ecu_amount', 12, 2)->nullable();

            $table->timestamp('approved_at')->nullable();
            $table->unsignedInteger('approved_by')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->string('decline_reason')->nullable();

            $table->string('invoice_number', 64)->nullable();
            $table->date('invoice_date')->nullable();
            $table->date('invoice_due_date')->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->string('payment_method', 32)->nullable();
            $table->string('payment_reference')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('asset_buyout_quotes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('asset_buyout_id')->index();
            $table->decimal('quote_amount', 12, 2)->nullable();
            $table->decimal('remaining_rent', 12, 2)->nullable();
            $table->decimal('quote_total', 12, 2)->nullable();
            $table->date('quoted_at')->nullable();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('recorded_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_buyout_quotes');
        Schema::dropIfExists('asset_buyouts');
    }
};

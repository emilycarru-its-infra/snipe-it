<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFacultyAgreementsTable extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('faculty_agreements')) {
            Schema::create('faculty_agreements', function (Blueprint $table) {
                $table->id();
                // Three forms feed into the same lifecycle: a new-laptop
                // pickup, a paid upgrade above the program base price,
                // and a lease-end buyout. Same table, different agreement
                // type — keeps the ledger view single-purpose.
                $table->string('agreement_type')->index();
                $table->unsignedInteger('user_id')->nullable()->index();
                $table->unsignedInteger('asset_id')->nullable()->index();
                $table->string('lifecycle_stage')->default('eligible')->index();
                $table->decimal('base_program_price', 15, 4)->nullable();
                $table->decimal('device_cost', 15, 4)->nullable();
                $table->decimal('top_up_amount', 15, 4)->nullable();
                $table->decimal('buyout_cost', 15, 4)->nullable();
                $table->string('payment_method')->nullable();
                $table->unsignedSmallInteger('installment_count')->nullable();
                $table->decimal('installment_amount', 15, 4)->nullable();
                $table->decimal('balance_paid', 15, 4)->default(0);
                $table->decimal('balance_remaining', 15, 4)->nullable();
                $table->timestamp('pdf_generated_at')->nullable();
                $table->string('pdf_path')->nullable();
                $table->timestamp('signed_at')->nullable();
                $table->string('signed_pdf_path')->nullable();
                $table->timestamp('sent_to_payroll_at')->nullable();
                $table->timestamp('deployed_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->string('old_asset_tag')->nullable();
                $table->string('old_serial')->nullable();
                $table->string('lease_contract')->nullable();
                // Optional link to Snipe's native acceptance record so a
                // follow-up PR can wire signed-PDF generation through
                // CheckoutAcceptance without another schema change.
                $table->unsignedInteger('checkout_acceptance_id')->nullable();
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
        Schema::dropIfExists('faculty_agreements');
    }
}

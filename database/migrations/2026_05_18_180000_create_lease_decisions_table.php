<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLeaseDecisionsTable extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('lease_decisions')) {
            Schema::create('lease_decisions', function (Blueprint $table) {
                $table->id();
                $table->string('contract_reference')->index();
                $table->string('decision_type');
                $table->date('decision_date')->nullable();
                $table->decimal('amount', 15, 4)->nullable();
                $table->string('status')->default('pending')->index();
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
        Schema::dropIfExists('lease_decisions');
    }
}

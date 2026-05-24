<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateContractsTables extends Migration
{
    public function up()
    {
        // The "contracts" table is the licenses-side analogue of "orders":
        // a first-class entity with its own lifecycle. TDX is the canonical
        // upstream source (tdx_id), but rows can also be created manually
        // or synthesized as umbrella parents derived from the ECU naming
        // convention "<Theme> FY<YY-YY> (<Product>)". The split between
        // theme / product / fiscal_year is what lets Snipe avoid TDX's
        // flat-ContractNumber UX failure.
        if (! Schema::hasTable('contracts')) {
            Schema::create('contracts', function (Blueprint $table) {
                $table->id();

                $table->unsignedInteger('tdx_id')->nullable()->unique();
                $table->boolean('is_synthesized')->default(false)->index();
                $table->unsignedBigInteger('parent_contract_id')->nullable()->index();

                $table->string('contract_number')->index();
                $table->string('name');
                $table->string('theme')->nullable()->index();
                $table->string('product')->nullable()->index();
                $table->string('fiscal_year', 16)->nullable()->index();

                $table->unsignedInteger('supplier_id')->nullable()->index();
                $table->string('type')->nullable()->index();
                $table->string('workflow_status')->nullable()->index();
                $table->boolean('is_active')->default(true)->index();

                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable()->index();
                $table->decimal('total_cost', 15, 4)->nullable();
                $table->string('currency', 8)->default('CAD');

                $table->text('description')->nullable();
                $table->text('comments_review')->nullable();

                // Promoted from TDX custom attributes so they're queryable
                // and reportable without joining a sidecar k/v table.
                $table->string('gl_code')->nullable()->index();
                $table->string('requisition_number')->nullable();
                $table->string('voucher_number')->nullable();
                $table->string('service_offering')->nullable();
                $table->string('ticket_url', 512)->nullable();
                $table->string('schedule_number')->nullable();

                $table->string('source')->default('manual')->index();
                $table->timestamp('tdx_modified_date')->nullable();
                $table->text('notes')->nullable();

                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->engine = 'InnoDB';
            });
        }

        // M:N: a license can be covered by multiple contracts across
        // different fiscal years; a contract can cover multiple licenses.
        // seats_covered is nullable to mean "all seats on this license".
        if (! Schema::hasTable('contract_license')) {
            Schema::create('contract_license', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('contract_id')->index();
                $table->unsignedInteger('license_id')->index();
                $table->unsignedInteger('seats_covered')->nullable();
                $table->date('valid_from')->nullable();
                $table->date('valid_to')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['contract_id', 'license_id']);
                $table->engine = 'InnoDB';
            });
        }

        // M:N for asset-only contracts (AppleCare on a specific MacBook
        // serial, FortiWifi warranty tied to a single appliance, etc.)
        if (! Schema::hasTable('contract_asset')) {
            Schema::create('contract_asset', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('contract_id')->index();
                $table->unsignedInteger('asset_id')->index();
                $table->date('valid_from')->nullable();
                $table->date('valid_to')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['contract_id', 'asset_id']);
                $table->engine = 'InnoDB';
            });
        }

        // Serials extracted from the TDX free-text Description plus any
        // manual entries. Indexed on `serial` so the top-bar search can
        // resolve a serial straight to a contract — the exact thing TDX
        // can't do.
        if (! Schema::hasTable('contract_serials')) {
            Schema::create('contract_serials', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('contract_id')->index();
                $table->string('serial')->index();
                $table->string('source')->default('manual');
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->engine = 'InnoDB';
            });
        }

        // Sidecar for TDX custom attributes we didn't promote, so we
        // don't lose information when TDX adds an attribute we haven't
        // mapped yet.
        if (! Schema::hasTable('contract_attributes')) {
            Schema::create('contract_attributes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('contract_id')->index();
                $table->string('name');
                $table->text('value')->nullable();
                $table->timestamps();
                $table->index(['contract_id', 'name']);
                $table->engine = 'InnoDB';
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('contract_attributes');
        Schema::dropIfExists('contract_serials');
        Schema::dropIfExists('contract_asset');
        Schema::dropIfExists('contract_license');
        Schema::dropIfExists('contracts');
    }
}

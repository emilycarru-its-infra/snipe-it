<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Introduce a `license_models` table that classifies each license row by
 * what it actually is: a SaaS subscription (no Product Key, no Checkout),
 * a per-machine perpetual license (Product Key + Seats), a license-server
 * pool (Seats, no Product Key), a site license, etc. Mirrors how
 * AssetModel classifies assets, but with behavior flags instead of
 * separate type tables.
 *
 * The `licenses` table gets a nullable FK `license_model_id`. Existing
 * licenses keep working unchanged — UI fallback for rows without a
 * model treats them as the default `product_key` type, which is what
 * Snipe's License form did before this change.
 *
 * This migration ships the table + FK + 6 seed types. PR 2 adds the
 * form/view conditional rendering; PR 3 adds the admin CRUD UI.
 */
class CreateLicenseModelsTable extends Migration
{
    public function up()
    {
        Schema::create('license_models', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->unique();
            // machine-readable code; controllers and views key off this.
            // Don't add new codes outside this list without updating the
            // UI conditional renderers in PR 2.
            $table->string('type_code', 50)->unique();
            $table->text('description')->nullable();
            $table->string('icon', 64)->nullable();

            // Field/section visibility flags. Each one toggles a section of
            // the License create/edit form and the License view page.
            $table->boolean('has_seats')->default(true);
            $table->boolean('has_product_key')->default(true);
            $table->boolean('has_checkout')->default(true);
            $table->boolean('has_expiration')->default(true);
            $table->boolean('has_user_email')->default(false);
            $table->boolean('has_reassignable')->default(true);
            // Renewal-aware vs one-time. Drives renewal warnings on the
            // dashboard and changes the wording on the expiration field.
            $table->boolean('is_subscription')->default(false);

            // Defaults applied when creating a license with this model.
            $table->unsignedInteger('default_seats')->default(1);
            $table->boolean('default_reassignable')->default(true);

            // Optional custom-fieldset reference, same pattern as AssetModel.
            // FK left out for now (custom_fieldsets table exists upstream
            // but we don't want this PR to depend on its load order). Wire
            // it up in PR 2 when we render the fields.
            $table->unsignedBigInteger('fieldset_id')->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedInteger('created_by')->nullable();
            $table->engine = 'InnoDB';
        });

        Schema::table('licenses', function (Blueprint $table) {
            $table->unsignedBigInteger('license_model_id')->nullable()->after('category_id');
            $table->foreign('license_model_id')
                ->references('id')->on('license_models')
                ->nullOnDelete();
        });

        // Seed the 6 default license models. Admins can add more via the
        // UI (PR 3) without code changes. The codes here are referenced
        // by the UI conditional renderers in PR 2, so don't rename them.
        $now = now();
        $seeds = [
            [
                'name' => 'Product Key',
                'type_code' => 'product_key',
                'description' => 'Software you install with an activation code. Per-seat checkout to users or assets. The Snipe default — picks this if you don\'t choose.',
                'icon' => 'fa-key',
                'has_seats' => true, 'has_product_key' => true, 'has_checkout' => true,
                'has_expiration' => true, 'has_user_email' => false, 'has_reassignable' => true,
                'is_subscription' => false, 'default_seats' => 1,
            ],
            [
                'name' => 'SaaS Subscription',
                'type_code' => 'saas',
                'description' => 'Hosted service with a recurring subscription. No product key, no per-seat checkout. Tracks renewal dates and vendor.',
                'icon' => 'fa-cloud',
                'has_seats' => false, 'has_product_key' => false, 'has_checkout' => false,
                'has_expiration' => true, 'has_user_email' => false, 'has_reassignable' => false,
                'is_subscription' => true, 'default_seats' => 0,
            ],
            [
                'name' => 'License Server / Concurrent',
                'type_code' => 'license_server',
                'description' => 'Concurrent-use pool managed by a license server (FlexLM, Houdini license server, FortiAnalyzer, etc.). Has seat count but no per-row activation code.',
                'icon' => 'fa-server',
                'has_seats' => true, 'has_product_key' => false, 'has_checkout' => true,
                'has_expiration' => true, 'has_user_email' => false, 'has_reassignable' => true,
                'is_subscription' => false, 'default_seats' => 1,
            ],
            [
                'name' => 'Site License',
                'type_code' => 'site_license',
                'description' => 'Institution-wide license. Open to everyone on campus, no per-seat assignment.',
                'icon' => 'fa-globe',
                'has_seats' => false, 'has_product_key' => false, 'has_checkout' => false,
                'has_expiration' => true, 'has_user_email' => false, 'has_reassignable' => false,
                'is_subscription' => false, 'default_seats' => 0,
            ],
            [
                'name' => 'Perpetual Per-Device',
                'type_code' => 'perpetual_per_device',
                'description' => 'One-time purchase, install on N devices forever. Has product key and seat count but no expiration.',
                'icon' => 'fa-infinity',
                'has_seats' => true, 'has_product_key' => true, 'has_checkout' => true,
                'has_expiration' => false, 'has_user_email' => false, 'has_reassignable' => true,
                'is_subscription' => false, 'default_seats' => 1,
            ],
            [
                'name' => 'Service Agreement',
                'type_code' => 'service',
                'description' => 'Infrastructure service (BCNET Internet Transit, Data Safe, DDS protection, etc.). Long-term, recurring, no checkout. Probably better off in the Contracts module.',
                'icon' => 'fa-network-wired',
                'has_seats' => false, 'has_product_key' => false, 'has_checkout' => false,
                'has_expiration' => true, 'has_user_email' => false, 'has_reassignable' => false,
                'is_subscription' => true, 'default_seats' => 0,
            ],
        ];

        foreach ($seeds as $row) {
            DB::table('license_models')->insert(array_merge($row, [
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }

    public function down()
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->dropForeign(['license_model_id']);
            $table->dropColumn('license_model_id');
        });
        Schema::dropIfExists('license_models');
    }
}

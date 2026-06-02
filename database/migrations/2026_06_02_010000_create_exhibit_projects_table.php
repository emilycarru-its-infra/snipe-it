<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exhibit projects — one row per student-project in a show (Grad Show,
 * MFA Thesis, Foundation, Type). Replaces the hand-maintained Grad Show
 * Numbers sheet: status, project type, requested device, submitted-file
 * / approved flags, peripherals, TDX id, across show + year. Anchors to
 * a Snipe user (student) and asset (the device reserved/assigned at
 * setup) rather than duplicating their details.
 */
class CreateExhibitProjectsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('exhibit_projects')) {
            return;
        }

        Schema::create('exhibit_projects', function (Blueprint $table) {
            $table->id();
            $table->string('show')->default('Grad Show')->index();
            $table->smallInteger('year')->index();
            // Snipe student user; student_name is the fallback label when
            // the applicant isn't (yet) a Snipe user.
            $table->unsignedInteger('user_id')->nullable()->index();
            $table->string('student_name')->nullable();
            // The physical device reserved from the pool and assigned at
            // setup (renamed ExhibitionXX-## in Munki).
            $table->unsignedInteger('asset_id')->nullable()->index();
            $table->string('status')->default('pending')->index();
            $table->string('project_type')->nullable();
            $table->text('project_details')->nullable();
            // Free string so combos ("iMac, iPad") survive, suggested from
            // a controlled set in the UI.
            $table->string('requested_device')->nullable();
            $table->string('peripherals')->nullable();
            $table->boolean('submitted_file')->default(false);
            $table->boolean('approved')->default(false);
            $table->string('tdx_id')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->engine = 'InnoDB';
        });
    }

    public function down()
    {
        Schema::dropIfExists('exhibit_projects');
    }
}

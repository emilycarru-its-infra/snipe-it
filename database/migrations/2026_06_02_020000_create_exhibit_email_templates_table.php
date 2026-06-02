<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Editable, DB-backed copy for the student emails the device admins send
 * each show cycle (equipment confirmation, need-to-contact). Admins edit
 * the subject + body in Snipe — the year-specific pickup dates/links get
 * typed into the body each cycle, the same habit as updating the TDX
 * response template today. Seeded from the handbook in
 * ExhibitEmailTemplateSeeder.
 */
class CreateExhibitEmailTemplatesTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('exhibit_email_templates')) {
            return;
        }

        Schema::create('exhibit_email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('subject');
            $table->text('body');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->engine = 'InnoDB';
        });

        // Seed the handbook templates here (not just in DatabaseSeeder) so
        // the deploy pipeline — which runs migrations, not seeders — gets
        // them on dev/prod. Idempotent firstOrCreate, safe to re-run.
        (new \Database\Seeders\ExhibitEmailTemplateSeeder)->run();
    }

    public function down()
    {
        Schema::dropIfExists('exhibit_email_templates');
    }
}

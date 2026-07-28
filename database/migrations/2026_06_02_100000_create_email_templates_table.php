<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB-backed overrides for the emails Snipe-IT sends. One row per email
 * "key" (see App\Mail\EmailRegistry). Both subject and body are nullable:
 * a blank column means "fall back to the built-in template" — the same
 * blank-to-default philosophy as the Agreements EULA copy. Phase A only
 * reads the registry for the preview hub; subject (Phase B) and body
 * (Phase C/D, rendered through lightncandy) wire these columns into the
 * actual send path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->increments('id');
            $table->string('key')->unique();
            $table->string('subject')->nullable()->default(null);
            $table->longText('body')->nullable()->default(null);
            $table->integer('updated_by')->nullable()->default(null);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};

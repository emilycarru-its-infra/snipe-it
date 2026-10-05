<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business values that used to be literals in code: the fiscal year's first
 * month, tax rates, the status labels a report treats as "off lease". Each is
 * declared in App\Services\Settings\Preferences with its default; a row here
 * exists only once an admin overrides one, so an empty table is exactly the
 * behaviour the code had before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runtime_settings', function (Blueprint $table) {
            $table->increments('id');
            $table->string('key', 191)->unique();
            $table->text('value')->nullable();
            $table->integer('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runtime_settings');
    }
};

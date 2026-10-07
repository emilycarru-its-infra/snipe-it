<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Formerly loaded one site's historical buyout cases onto the tracker. Those
 * records are personal data and do not belong in a public repository, so the
 * case list was removed. Databases that ran this already hold the rows; a new
 * database starts with an empty tracker and records buyouts through the app.
 *
 * The file is kept so the migration name stays recorded where it ran.
 */
return new class extends Migration
{
    public function up(): void
    {
    }

    public function down(): void
    {
    }
};

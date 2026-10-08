<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Formerly completed one site's lease register: it added the schedules that
 * had no register row and renumbered the affected fiscal years. The schedule
 * list was that site's real lease contracts, which do not belong in a public
 * repository, so it was removed. Databases that ran this already hold the
 * rows; a new database builds its register through the app, and
 * `snipeit:sync-lease-names` names assets from whatever the register holds.
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

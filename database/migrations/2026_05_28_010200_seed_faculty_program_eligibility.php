<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        $legacyGroup = env('USER_FORM_GROUP', 'Regular Faculty');

        $groupId = DB::table('permission_groups')->where('name', $legacyGroup)->value('id');

        if (! $groupId) {
            Log::info("seed_faculty_program_eligibility: group '{$legacyGroup}' not found; skipping seed (admin can wire it up via Settings → Forms)");
            return;
        }

        $exists = DB::table('form_eligibility')
            ->where('form_slug', 'faculty-program')
            ->where('group_id', $groupId)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('form_eligibility')->insert([
            'form_slug'  => 'faculty-program',
            'group_id'   => $groupId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('form_eligibility')->where('form_slug', 'faculty-program')->delete();
    }
};

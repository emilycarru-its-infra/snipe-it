<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Promote the buyout-request CC list from the BUYOUT_REQUEST_CC env fallback
 * into the Settings → Emails override row, so the GUI displays and owns the
 * live list instead of an empty field silently falling back to the app
 * setting. Idempotent: a CC an admin has already saved is never touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $seed = collect(explode(',', (string) config('leasing.buyout_request_cc')))
            ->map(fn ($email) => trim($email))
            ->filter()
            ->implode(',');

        if ($seed === '') {
            return;
        }

        $existing = DB::table('email_templates')->where('key', 'request.asset_buyout')->first();

        if ($existing && filled($existing->cc)) {
            return;
        }

        if ($existing) {
            DB::table('email_templates')
                ->where('id', $existing->id)
                ->update(['cc' => $seed, 'updated_at' => now()]);

            return;
        }

        DB::table('email_templates')->insert([
            'key' => 'request.asset_buyout',
            'cc' => $seed,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Data seed — reversing would clobber admin edits, so leave the row alone.
    }
};

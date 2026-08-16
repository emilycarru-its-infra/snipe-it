<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the Grad Show confirmation letter — the announcement that went to
 * every approved student in 2026, previously composed by hand and sent
 * through TDX — as an editable exhibit email template. The dates and
 * rooms change each cycle; the composer sheet is where they get edited
 * and saved back. Insert-only: if the key exists, an admin's edits win.
 */
return new class extends Migration
{
    public function up()
    {
        if (DB::table('exhibit_email_templates')->where('key', 'grad_show_confirmation')->exists()) {
            return;
        }

        DB::table('exhibit_email_templates')->insert([
            'key' => 'grad_show_confirmation',
            'name' => 'Grad Show Confirmation',
            'subject' => 'Your approved {{show}} equipment — pickup details',
            'body' => <<<'BODY'
Hello,

You are being sent this info because you have an approved Grad Show equipment request. The following discusses how to obtain your equipment for The Show.

Media Resources Equipment:
For students borrowing equipment for the exhibition from Media Resources (Projectors, Speakers, Headphones, Media Players, External Displays and iPads).

Please check the booking confirmation email we sent detailing the equipment you requested for the Grad Show.

Your booking starts at 8:30am May 5th — when installations begin — and runs until after the end of the Grad Show.

Media Resources hours are 8:30am to 5:30pm; closed between 2-3pm each day during the installation period (May 5 — May 8).

You can pick up your equipment anytime during the installation period. Your booking will not be cancelled.

Pick up your equipment when your area is prepared, and you are ready to install.
Arrange installation assistance with the Gallery Techs.

iPads:
iPads are distributed by Media Resources.

After picking up your iPad from Media Resources, your project can be loaded onto the iPad.

iPad display cases — as well as technical assistance for projects — are available on the Show install days.

Computers:
For students borrowing iMacs, Mac Minis and Windows PCs.
All desktop computers will be available for pick up and project installations in the Foundation Mac Lab (D3360) between 9:30am to 4:30pm during the exhibition install dates (May 5 – May 8).

To get your computer, drop by D3360 during the installation period, and we will help you set up your project. We will be away for lunch break between 1-2PM.

If your plans change, and you no longer need equipment, please let us know. This allows us to allocate unused equipment for students on the waitlist.

Congratulations from all of us.
BODY,
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down()
    {
        DB::table('exhibit_email_templates')->where('key', 'grad_show_confirmation')->delete();
    }
};

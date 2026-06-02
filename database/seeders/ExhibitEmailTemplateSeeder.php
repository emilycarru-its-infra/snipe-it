<?php

namespace Database\Seeders;

use App\Models\ExhibitEmailTemplate;
use Illuminate\Database\Seeder;

/**
 * Seeds the two student emails the device admins send each show cycle,
 * lifted from the handbook
 *   devices/operations/exhibits/the-grad-show/resources-links.md
 *   devices/operations/exhibits/the-grad-show/operational-timeline-tasks.md
 *
 * Idempotent (firstOrCreate on `key`) so it's safe to run from the
 * table migration and again via `php artisan db:seed`: a template is
 * seeded once, then owned by the admins who edit it in-app each year —
 * re-running never clobbers their pickup-date edits.
 */
class ExhibitEmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $tpl) {
            ExhibitEmailTemplate::firstOrCreate(
                ['key' => $tpl['key']],
                [
                    'name' => $tpl['name'],
                    'subject' => $tpl['subject'],
                    'body' => $tpl['body'],
                    'enabled' => true,
                ]
            );
        }
    }

    private function templates(): array
    {
        return [
            [
                'key' => 'equipment_confirmation',
                'name' => 'Equipment Confirmation',
                'subject' => 'Your {{show}} {{year}} equipment request — pickup & file submission',
                'body' => <<<'BODY'
Hello {{student_name}},

You are being sent this info because you have an approved {{show}} {{year}} equipment request. The following explains how to obtain your equipment and submit your project files.

Your requested equipment: {{requested_device}}

— Media Resources equipment —
For equipment from Media Resources (projectors, speakers, media players, external displays and iPads): please check the booking confirmation email detailing the equipment you requested. Media Resources hours are 8:30am–4:30pm each day during the installation period. Pick up your equipment when your area is prepared and you are ready to install, and arrange installation assistance with the Gallery Techs.

— iPads —
iPads are distributed by Media Resources. After picking up your iPad, your project can be loaded onto it. iPad display cases and technical assistance are available on the install days.

— Computers —
For iMacs, Mac minis and Windows PCs: all desktop computers will be available for pickup and project installation in the Foundation Mac Lab (D3360) during the exhibition install dates.

Please submit your final project files early using the submission link we provided. Make sure to include your name in your project's title. If we've received your file, we'll install it and make sure it's running properly before we deploy and secure your computer for the show. Otherwise, drop by D3360 during the installation period and we'll help you set up your project.

If your plans change and you no longer need equipment, please let us know so we can allocate it to a student on the waitlist.

Congratulations from all of us.
BODY,
            ],
            [
                'key' => 'need_to_contact',
                'name' => 'Need to Contact / Clarification',
                'subject' => 'Your {{show}} {{year}} equipment request — a quick question',
                'body' => <<<'BODY'
Hello {{student_name}},

Thanks for your {{show}} {{year}} equipment request ({{requested_device}}). Before we finalize your setup we need a little more detail about your project so we can make sure the right equipment and software are ready for you.

Could you reply with a short description of how your project will run (for example: looping video, website, a specific application), and let us know if you have any special requirements? If it's easier, you're welcome to visit us in the Foundation Mac Lab (D3360) during our hours so we can set it up together.

Thanks, and we look forward to getting your project on display.
BODY,
            ],
        ];
    }
}

<?php

namespace Tests\Feature\Exhibits;

use App\Mail\ExhibitNotificationMail;
use App\Models\Exhibit;
use App\Models\ExhibitEmailTemplate;
use App\Models\ExhibitProject;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The exhibit board's composer sheet — the wave-announce pattern aimed at
 * show students. What the sheet shows is what goes out: edited wording,
 * per-student merge fields, save-back to the template, tests to named
 * readers.
 */
class ExhibitComposeTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    private function statusId(): int
    {
        return (int) (\App\Models\ExhibitStatus::value('id')
            ?? \App\Models\ExhibitStatus::create(['name' => 'Pending', 'slug' => 'pending'])->id);
    }

    private function approvedProject(Exhibit $exhibit, int $year, string $name): ExhibitProject
    {
        $student = User::factory()->create();

        return ExhibitProject::create([
            'student_name' => $name,
            'user_id' => $student->id,
            'year' => $year,
            'exhibit_id' => $exhibit->id,
            'status_id' => $this->statusId(),
            'requested_device' => 'iMac',
            'approved' => true,
        ]);
    }

    public function test_compose_sends_edited_wording_with_merge_fields_resolved()
    {
        Mail::fake();

        $exhibit = Exhibit::firstOrCreate(['name' => 'Grad Show']);
        $this->approvedProject($exhibit, 2026, 'Approved One');
        $this->approvedProject($exhibit, 2026, 'Approved Two');
        // Not approved — must not be mailed.
        ExhibitProject::create([
            'student_name' => 'Pending Student',
            'user_id' => User::factory()->create()->id,
            'year' => 2026,
            'exhibit_id' => $exhibit->id,
            'status_id' => $this->statusId(),
            'approved' => false,
        ]);

        $this->actingAs($this->superuser())
            ->post(route('exhibit-projects.compose'), [
                'exhibit' => $exhibit->id,
                'year' => 2026,
                'subject' => 'Your {{show}} equipment',
                'body' => 'Hello — your {{requested_device}} is ready.',
            ])
            ->assertRedirect();

        Mail::assertSent(ExhibitNotificationMail::class, 2);
        Mail::assertSent(ExhibitNotificationMail::class, function (ExhibitNotificationMail $mail) {
            return $mail->renderedSubject === 'Your Grad Show equipment'
                && str_contains($mail->renderedBody, 'your iMac is ready');
        });
    }

    public function test_test_send_goes_to_the_actor_not_the_students()
    {
        Mail::fake();

        $exhibit = Exhibit::firstOrCreate(['name' => 'Grad Show']);
        $this->approvedProject($exhibit, 2026, 'Approved One');
        $actor = $this->superuser();

        $this->actingAs($actor)
            ->post(route('exhibit-projects.compose'), [
                'exhibit' => $exhibit->id,
                'year' => 2026,
                'subject' => 'Subject',
                'body' => 'Body',
                'test' => 1,
            ])
            ->assertRedirect();

        Mail::assertSent(ExhibitNotificationMail::class, 1);
        Mail::assertSent(ExhibitNotificationMail::class, function (ExhibitNotificationMail $mail) use ($actor) {
            return $mail->test && $mail->hasTo($actor->email);
        });
    }

    public function test_save_template_writes_the_wording_back()
    {
        $exhibit = Exhibit::firstOrCreate(['name' => 'Grad Show']);
        $template = ExhibitEmailTemplate::create([
            'key' => 'compose_test',
            'name' => 'Compose Test',
            'subject' => 'Old subject',
            'body' => 'Old body',
            'enabled' => true,
        ]);

        $this->actingAs($this->superuser())
            ->post(route('exhibit-projects.compose'), [
                'exhibit' => $exhibit->id,
                'year' => 2026,
                'subject' => 'New subject',
                'body' => 'New body',
                'template_id' => $template->id,
                'save_template' => 1,
            ])
            ->assertRedirect();

        $template->refresh();
        $this->assertEquals('New subject', $template->subject);
        $this->assertEquals('New body', $template->body);
    }
}

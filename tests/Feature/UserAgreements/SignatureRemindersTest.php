<?php

namespace Tests\Feature\UserAgreements;

use App\Mail\UserAgreementSignatureReminderMail;
use App\Models\Asset;
use App\Models\Statuslabel;
use App\Models\User;
use App\Models\UserAgreement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SignatureRemindersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The command honours the global alerts_enabled gate now.
        // Settings::getSettings() is memoised by the model, so reach
        // through and ensure the flag is on for the tests that
        // exercise the command's happy path.
        $settings = \App\Models\Setting::getSettings();
        if ($settings) {
            $settings->alerts_enabled = 1;
            $settings->saveQuietly();
        }
    }

    /**
     * Build a UserAgreement row directly via the query builder so the
     * model's `saved` hook does NOT fire — that hook auto-calls
     * sendForSignature() for `agreement_sent` rows and sends the
     * initial signature-request mail, which would be captured by
     * Mail::fake() and break the assertNothingSent negative tests.
     */
    private function pendingAgreement(array $overrides = []): UserAgreement
    {
        $status = Statuslabel::factory()->rtd()->create();
        $user   = User::factory()->create();
        $asset  = Asset::factory()->create(['status_id' => $status->id]);

        $attributes = array_merge([
            'agreement_type'  => 'pickup',
            'user_id'         => $user->id,
            'asset_id'        => $asset->id,
            'lifecycle_stage' => 'agreement_sent',
            'reminders_sent'  => 0,
            'created_at'      => now(),
            'updated_at'      => now(),
        ], $overrides);

        $id = \DB::table('user_agreements')->insertGetId($attributes);

        return UserAgreement::findOrFail($id);
    }

    public function test_sends_when_interval_passed_and_under_max(): void
    {
        Mail::fake();

        $agreement = $this->pendingAgreement([
            'reminders_sent'        => 0,
            'last_reminder_sent_at' => null,
        ]);
        // Backdate updated_at so the initial-send guard doesn't trip.
        $agreement->updated_at = Carbon::now()->subDays(5);
        $agreement->saveQuietly();

        Artisan::call('snipeit:user-agreement-signature-reminders');

        Mail::assertSent(UserAgreementSignatureReminderMail::class, 1);
        $agreement->refresh();
        $this->assertSame(1, $agreement->reminders_sent);
        $this->assertNotNull($agreement->last_reminder_sent_at);
    }

    public function test_does_not_send_when_already_signed(): void
    {
        Mail::fake();

        $agreement = $this->pendingAgreement([
            'lifecycle_stage' => 'agreement_signed',
            'signed_at'       => Carbon::now()->subDays(1),
        ]);
        $agreement->updated_at = Carbon::now()->subDays(5);
        $agreement->saveQuietly();

        Artisan::call('snipeit:user-agreement-signature-reminders');

        Mail::assertNothingSent();
    }

    public function test_does_not_send_when_within_interval(): void
    {
        Mail::fake();

        $agreement = $this->pendingAgreement([
            'last_reminder_sent_at' => Carbon::now()->subDay(),
        ]);

        Artisan::call('snipeit:user-agreement-signature-reminders');

        Mail::assertNothingSent();
    }

    public function test_does_not_send_when_at_max(): void
    {
        Mail::fake();

        $agreement = $this->pendingAgreement([
            'reminders_sent'        => 5,
            'last_reminder_sent_at' => Carbon::now()->subDays(10),
        ]);

        Artisan::call('snipeit:user-agreement-signature-reminders');

        Mail::assertNothingSent();
    }

    public function test_dry_run_does_not_write_or_send(): void
    {
        Mail::fake();

        $agreement = $this->pendingAgreement([
            'last_reminder_sent_at' => Carbon::now()->subDays(5),
        ]);
        $original = $agreement->reminders_sent;

        Artisan::call('snipeit:user-agreement-signature-reminders', ['--dry-run' => true]);

        Mail::assertNothingSent();
        $this->assertSame($original, $agreement->fresh()->reminders_sent);
    }

    public function test_disabled_by_config(): void
    {
        Mail::fake();
        config()->set('forms.signature_reminders.enabled', false);

        $agreement = $this->pendingAgreement([
            'last_reminder_sent_at' => Carbon::now()->subDays(10),
        ]);

        Artisan::call('snipeit:user-agreement-signature-reminders');

        Mail::assertNothingSent();
    }

    public function test_does_not_send_when_global_alerts_disabled(): void
    {
        Mail::fake();
        $settings = \App\Models\Setting::getSettings();
        $settings->alerts_enabled = 0;
        $settings->saveQuietly();

        $agreement = $this->pendingAgreement([
            'last_reminder_sent_at' => Carbon::now()->subDays(10),
        ]);

        Artisan::call('snipeit:user-agreement-signature-reminders');

        Mail::assertNothingSent();
    }
}

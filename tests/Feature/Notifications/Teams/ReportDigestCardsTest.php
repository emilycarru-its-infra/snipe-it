<?php

namespace Tests\Feature\Notifications\Teams;

use App\Mail\EmailDelivery;
use App\Mail\ExpiringAssetsMail;
use App\Models\Asset;
use App\Models\Consumable;
use App\Models\EmailTemplate;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\ExpectedCheckinNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PostsThroughRelay;
use Tests\TestCase;

/**
 * The nightly digests, as cards carrying the whole listing.
 *
 * Rod's call, and the reason these are tables rather than a count and a link:
 * the count is what the heading already says, and the rows are the work.
 */
#[Group('notifications')]
class ReportDigestCardsTest extends TestCase
{
    use PostsThroughRelay;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();

        $this->fakeRelay();

        $this->settings->enableAlertEmail('alerts@example.com')->setAlertInterval(60);
    }

    private function tableRows(array $card): array
    {
        foreach ($card['body'] as $block) {
            if ($block['type'] === 'Table') {
                // Drop the header row.
                return array_slice($block['rows'], 1);
            }
        }

        return [];
    }

    public function test_the_low_inventory_digest_posts_every_low_item()
    {
        Consumable::factory()->count(4)->create(['qty' => 1, 'min_amt' => 5]);

        $this->artisan('snipeit:inventory-alerts')->assertSuccessful();

        $cards = $this->postedCards();

        $this->assertCount(1, $cards);
        $this->assertSame(4, count($this->tableRows($cards[0])));
        $this->assertSame('4 items', $cards[0]['body'][1]['text']);
    }

    public function test_the_low_inventory_email_is_not_also_sent()
    {
        Consumable::factory()->count(2)->create(['qty' => 1, 'min_amt' => 5]);

        $this->artisan('snipeit:inventory-alerts')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_an_admin_can_have_both_the_card_and_the_email()
    {
        EmailTemplate::updateOrCreate(['key' => 'report.expiring_assets'], ['delivery' => EmailDelivery::BOTH]);
        $this->expiringAsset();

        $this->artisan('snipeit:expiring-alerts')->assertSuccessful();

        Mail::assertSent(ExpiringAssetsMail::class);
        $this->assertNotEmpty($this->postedCards());
    }

    public function test_nothing_is_posted_when_there_is_nothing_to_report()
    {
        $this->artisan('snipeit:inventory-alerts')->assertSuccessful();

        $this->assertNoCardPosted();
    }

    public function test_the_expiring_assets_digest_carries_tags_and_dates_and_replaces_the_email()
    {
        $this->expiringAsset();
        $this->expiringAsset();

        $this->artisan('snipeit:expiring-alerts')->assertSuccessful();

        Mail::assertNotSent(ExpiringAssetsMail::class);

        $cards = $this->postedCards();
        $this->assertNotEmpty($cards);
        $this->assertNotEmpty($this->tableRows($cards[0]));
    }

    public function test_a_long_digest_is_posted_as_several_cards_rather_than_being_trimmed()
    {
        // The whole point of carrying every row: a report bigger than one card
        // splits, and every item still appears somewhere.
        Consumable::factory()->count(120)->create(['qty' => 1, 'min_amt' => 5]);

        $this->artisan('snipeit:inventory-alerts')->assertSuccessful();

        $cards = $this->postedCards();
        $carried = array_sum(array_map(fn ($card) => count($this->tableRows($card)), $cards));

        $this->assertSame(120, $carried);

        foreach ($cards as $card) {
            $this->assertLessThanOrEqual(24576, strlen((string) json_encode($card)));
        }
    }

    public function test_the_expected_checkin_digest_still_emails_the_users_their_own_reminders()
    {
        // The user-facing half of this command is untouched: it is addressed
        // to the person holding the asset, not to us.
        $settings = Setting::getSettings();
        $settings->due_checkin_days = 7;
        $settings->saveQuietly();

        $user = User::factory()->create();
        $asset = Asset::factory()->assignedToUser($user)->create();
        $asset->expected_checkin = now()->addDays(2)->format('Y-m-d');
        $asset->saveQuietly();

        $this->artisan('snipeit:expected-checkin')->assertSuccessful();

        Notification::assertSentTo($user, ExpectedCheckinNotification::class);
    }

    /**
     * An asset whose warranty lands inside the alert interval. The factory's
     * configure() hook overwrites asset_eol_date, so the warranty months are
     * what this leans on.
     */
    private function expiringAsset(): Asset
    {
        return Asset::factory()->create([
            'purchase_date' => now()->subDays(356)->format('Y-m-d'),
            'warranty_months' => 12,
            'archived' => 0,
        ]);
    }
}

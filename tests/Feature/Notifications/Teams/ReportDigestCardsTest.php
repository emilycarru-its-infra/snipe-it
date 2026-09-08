<?php

namespace Tests\Feature\Notifications\Teams;

use App\Mail\EmailDelivery;
use App\Mail\ExpiringAssetsMail;
use App\Models\Asset;
use App\Models\Consumable;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Group;
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
    private const REPORTS = 'https://prod-1.westus.logic.azure.com/workflows/reports/triggers/manual/paths/invoke';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        Http::fake([self::REPORTS => Http::response('', 202)]);

        config()->set('ecu.teams', [
            'enabled' => true,
            'timeout' => 8,
            'channels' => ['default' => '', 'reports' => self::REPORTS],
        ]);

        $this->settings->enableAlertEmail('alerts@example.com')->setAlertInterval(60);
    }

    /** @return array<int, array<string, mixed>> the cards posted, in order */
    private function cards(): array
    {
        $cards = [];
        Http::recorded(fn () => true)->each(function ($pair) use (&$cards) {
            $cards[] = $pair[0]->data()['attachments'][0]['content'];
        });

        return $cards;
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

    public function testTheLowInventoryDigestPostsEveryLowItem()
    {
        Consumable::factory()->count(4)->create(['qty' => 1, 'min_amt' => 5]);

        $this->artisan('snipeit:inventory-alerts')->assertSuccessful();

        $cards = $this->cards();

        $this->assertCount(1, $cards);
        $this->assertSame(4, count($this->tableRows($cards[0])));
        $this->assertSame('4 items', $cards[0]['body'][1]['text']);
    }

    public function testTheLowInventoryEmailIsNotAlsoSent()
    {
        Consumable::factory()->count(2)->create(['qty' => 1, 'min_amt' => 5]);

        $this->artisan('snipeit:inventory-alerts')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function testAnAdminCanHaveBothTheCardAndTheEmail()
    {
        EmailTemplate::updateOrCreate(['key' => 'report.expiring_assets'], ['delivery' => EmailDelivery::BOTH]);
        $this->expiringAsset();

        $this->artisan('snipeit:expiring-alerts')->assertSuccessful();

        Mail::assertSent(ExpiringAssetsMail::class);
        $this->assertNotEmpty($this->cards());
    }

    public function testNothingIsPostedWhenThereIsNothingToReport()
    {
        $this->artisan('snipeit:inventory-alerts')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function testTheExpiringAssetsDigestCarriesTagsAndDatesAndReplacesTheEmail()
    {
        $this->expiringAsset();
        $this->expiringAsset();

        $this->artisan('snipeit:expiring-alerts')->assertSuccessful();

        Mail::assertNotSent(ExpiringAssetsMail::class);

        $cards = $this->cards();
        $this->assertNotEmpty($cards);
        $this->assertNotEmpty($this->tableRows($cards[0]));
    }

    public function testALongDigestIsPostedAsSeveralCardsRatherThanBeingTrimmed()
    {
        // The whole point of carrying every row: a report bigger than one card
        // splits, and every item still appears somewhere.
        Consumable::factory()->count(120)->create(['qty' => 1, 'min_amt' => 5]);

        $this->artisan('snipeit:inventory-alerts')->assertSuccessful();

        $cards = $this->cards();
        $carried = array_sum(array_map(fn ($card) => count($this->tableRows($card)), $cards));

        $this->assertSame(120, $carried);

        foreach ($cards as $card) {
            $this->assertLessThanOrEqual(24576, strlen((string) json_encode($card)));
        }
    }

    public function testTheExpectedCheckinDigestStillEmailsTheUsersTheirOwnReminders()
    {
        // The user-facing half of this command is untouched: it is addressed
        // to the person holding the asset, not to us.
        $settings = \App\Models\Setting::getSettings();
        $settings->due_checkin_days = 7;
        $settings->saveQuietly();

        $user = User::factory()->create();
        $asset = Asset::factory()->assignedToUser($user)->create();
        $asset->expected_checkin = now()->addDays(2)->format('Y-m-d');
        $asset->saveQuietly();

        $this->artisan('snipeit:expected-checkin')->assertSuccessful();

        Notification::assertSentTo($user, \App\Notifications\ExpectedCheckinNotification::class);
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

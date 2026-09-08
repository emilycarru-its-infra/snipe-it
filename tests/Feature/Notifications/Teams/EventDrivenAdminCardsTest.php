<?php

namespace Tests\Feature\Notifications\Teams;

use App\Actions\CheckoutRequests\CancelCheckoutRequestAction;
use App\Actions\CheckoutRequests\CreateCheckoutRequestAction;
use App\Mail\EmailDelivery;
use App\Mail\FacultyProgramSubmissionMail;
use App\Models\Asset;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Notifications\AcceptanceItemAcceptedNotification;
use App\Notifications\AcceptanceItemDeclinedNotification;
use App\Services\Teams\TeamsNotifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The event-driven admin notifications that used to be email only.
 */
#[Group('notifications')]
class EventDrivenAdminCardsTest extends TestCase
{
    private const HOOK = 'https://prod-1.westus.logic.azure.com/workflows/x/triggers/manual/paths/invoke';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        $this->withoutDefer();
        Http::fake([self::HOOK => Http::response('', 202)]);

        config()->set('ecu.teams', [
            'enabled' => true,
            'timeout' => 8,
            'channels' => array_fill_keys(['default', 'devices', 'procurement', 'reports', 'requests'], self::HOOK),
        ]);
    }

    private function cardTitle(): string
    {
        $title = '';
        Http::assertSent(function ($request) use (&$title) {
            $title = $request['attachments'][0]['content']['body'][0]['text'];

            return true;
        });

        return $title;
    }

    public function testRequestingAnAssetPostsACardInsteadOfEmailingTheAlertAddress()
    {
        $this->settings->enableAlertEmail('alerts@example.com');

        $asset = Asset::factory()->requestable()->create();
        $user = User::factory()->create();
        $this->actingAs($user);

        CreateCheckoutRequestAction::run($asset, $user);

        Notification::assertNothingSent();
        $this->assertStringContainsString('requested', strtolower($this->cardTitle()));
    }

    public function testCancellingARequestPostsItsOwnCard()
    {
        $this->settings->enableAlertEmail('alerts@example.com');

        $asset = Asset::factory()->requestable()->create();
        $user = User::factory()->create();
        $this->actingAs($user);
        CreateCheckoutRequestAction::run($asset, $user);
        Http::fake([self::HOOK => Http::response('', 202)]);

        CancelCheckoutRequestAction::run($asset, $user);

        $this->assertStringContainsString('canceled', strtolower($this->cardTitle()));
    }

    public function testAnAdminCanPutAssetRequestsBackOnEmail()
    {
        $this->settings->enableAlertEmail('alerts@example.com');
        EmailTemplate::updateOrCreate(['key' => 'request.asset'], ['delivery' => EmailDelivery::EMAIL]);

        $user = User::factory()->create();
        $this->actingAs($user);

        CreateCheckoutRequestAction::run(Asset::factory()->requestable()->create(), $user);

        Http::assertNothingSent();
        Notification::assertSentTimes(\App\Notifications\RequestAssetNotification::class, 1);
    }

    public function testTheAcceptanceCardsCarryTheItemAndTheSigner()
    {
        $params = [
            'item_tag' => 'TEST-0003',
            'item_name' => 'Sample Laptop',
            'item_model' => 'MacBook Pro 14-inch',
            'item_serial' => 'TESTSERIAL3',
            'item_status' => 'Deployed',
            'accepted_date' => '2026-09-08',
            'declined_date' => '2026-09-08',
            'assigned_to' => 'Sample Person',
            'company_name' => 'Sample Company',
            'qty' => 1,
            'note' => 'Signed at the desk.',
        ];

        foreach ([
            AcceptanceItemAcceptedNotification::class => 'accepted',
            AcceptanceItemDeclinedNotification::class => 'declined',
        ] as $class => $word) {
            Http::fake([self::HOOK => Http::response('', 202)]);

            app(TeamsNotifier::class)->announce('acceptance.'.($word === 'accepted' ? 'accepted_admin' : 'declined'), new $class($params));

            $card = null;
            Http::assertSent(function ($request) use (&$card) {
                $card = $request['attachments'][0]['content'];

                return true;
            });

            $facts = array_combine(array_column($card['body'][2]['facts'], 'title'), array_column($card['body'][2]['facts'], 'value'));

            $this->assertStringContainsString($word, strtolower($card['body'][0]['text']));
            $this->assertSame('TEST-0003', $facts['Asset Tag']);
            $this->assertSame('Sample Person', $facts['Assigned To']);
        }
    }

    public function testAFacultyProgramSubmissionPostsACardInsteadOfEmailingTheProgram()
    {
        \App\Services\FacultyProgramNotifier::submitted($this->pickupAgreement(), null, false);

        Mail::assertNotSent(FacultyProgramSubmissionMail::class);
        $this->assertStringContainsString('Faculty Laptop Program', $this->cardTitle());
    }

    public function testAnUpdatedFacultyProgramSubmissionSaysSo()
    {
        \App\Services\FacultyProgramNotifier::submitted($this->pickupAgreement(), null, true);

        $this->assertStringContainsString('updated', $this->cardTitle());
    }

    /**
     * A pickup agreement, inserted directly so the model's saved hook does not
     * fire and send the signature request this test is not about.
     */
    private function pickupAgreement(): \App\Models\UserAgreement
    {
        $id = \DB::table('user_agreements')->insertGetId([
            'agreement_type' => 'pickup',
            'user_id' => User::factory()->create()->id,
            'asset_id' => Asset::factory()->laptopMbp()->create()->id,
            'lifecycle_stage' => 'agreement_sent',
            'reminders_sent' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return \App\Models\UserAgreement::findOrFail($id);
    }
}

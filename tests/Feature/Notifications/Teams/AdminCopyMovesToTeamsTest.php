<?php

namespace Tests\Feature\Notifications\Teams;

use App\Events\CheckoutableCheckedIn;
use App\Events\CheckoutableCheckedOut;
use App\Mail\CheckinAssetMail;
use App\Mail\CheckoutAssetMail;
use App\Mail\EmailDelivery;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Category;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The point of the whole change: on a checkout or check-in the person holding
 * the item keeps their email, and the copy that used to land in the admin
 * inbox becomes a card instead.
 */
#[Group('notifications')]
class AdminCopyMovesToTeamsTest extends TestCase
{
    private const DEVICES = 'https://prod-1.westus.logic.azure.com/workflows/devices/triggers/manual/paths/invoke';

    private Category $category;

    private Asset $asset;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->withoutDefer();
        Http::fake([self::DEVICES => Http::response('', 202)]);

        config()->set('ecu.teams', [
            'enabled' => true,
            'timeout' => 8,
            'channels' => ['default' => '', 'devices' => self::DEVICES],
        ]);

        $this->settings->enableAdminCC('cc@example.com');

        $this->category = Category::factory()->create([
            'checkin_email' => true,
            'eula_text' => null,
            'require_acceptance' => false,
            'use_default_eula' => false,
        ]);
        $model = AssetModel::factory()->for($this->category)->create();
        $this->asset = Asset::factory()->for($model, 'model')->create();
        $this->user = User::factory()->create();
    }

    private function checkOut(): void
    {
        event(new CheckoutableCheckedOut($this->asset, $this->user, User::factory()->superuser()->create(), 'Loaned'));
    }

    public function test_the_user_keeps_their_email_and_the_admin_copy_becomes_a_card()
    {
        $this->checkOut();

        Mail::assertSent(CheckoutAssetMail::class, fn (CheckoutAssetMail $mail) => $mail->hasTo($this->user->email));
        Mail::assertNotSent(CheckoutAssetMail::class, fn (CheckoutAssetMail $mail) => $mail->hasCc('cc@example.com'));

        Http::assertSent(fn ($request) => $request->url() === self::DEVICES);
    }

    public function test_the_same_holds_on_checkin()
    {
        $asset = Asset::factory()->for(AssetModel::factory()->for($this->category)->create(), 'model')
            ->assignedToUser($this->user)
            ->create();

        event(new CheckoutableCheckedIn($asset, $this->user, User::factory()->superuser()->create(), ''));

        Mail::assertSent(CheckinAssetMail::class, fn (CheckinAssetMail $mail) => $mail->hasTo($this->user->email));
        Mail::assertNotSent(CheckinAssetMail::class, fn (CheckinAssetMail $mail) => $mail->hasCc('cc@example.com'));

        Http::assertSent(fn ($request) => $request->url() === self::DEVICES);
    }

    public function test_an_admin_can_put_the_email_copy_back_without_a_deploy()
    {
        EmailTemplate::updateOrCreate(['key' => 'checkout.asset'], ['delivery' => EmailDelivery::BOTH]);

        $this->checkOut();

        Mail::assertSent(CheckoutAssetMail::class, fn (CheckoutAssetMail $mail) => $mail->hasCc('cc@example.com'));
        Http::assertSent(fn ($request) => $request->url() === self::DEVICES);
    }

    public function test_choosing_email_only_stops_the_card()
    {
        EmailTemplate::updateOrCreate(['key' => 'checkout.asset'], ['delivery' => EmailDelivery::EMAIL]);

        $this->checkOut();

        Mail::assertSent(CheckoutAssetMail::class, fn (CheckoutAssetMail $mail) => $mail->hasCc('cc@example.com'));
        Http::assertNothingSent();
    }

    public function test_routing_one_kind_of_checkout_does_not_move_the_others()
    {
        // Each checkoutable has its own registry key, so accessories can stay
        // on email while assets post cards.
        EmailTemplate::updateOrCreate(['key' => 'checkout.accessory'], ['delivery' => EmailDelivery::EMAIL]);

        $this->checkOut();

        Mail::assertNotSent(CheckoutAssetMail::class, fn (CheckoutAssetMail $mail) => $mail->hasCc('cc@example.com'));
    }
}

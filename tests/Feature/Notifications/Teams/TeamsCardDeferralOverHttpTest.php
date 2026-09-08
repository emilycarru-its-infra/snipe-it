<?php

namespace Tests\Feature\Notifications\Teams;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Cards are posted after the response, and deferred callbacks only run over
 * HTTP when InvokeDeferredCallbacks is in the kernel's global middleware.
 * This fork keeps a legacy Http\Kernel, where defer() was a silent no-op for
 * months — and every other test hides that by calling withoutDefer().
 *
 * So this one deliberately does not: it drives a real API check-in through
 * the kernel, terminate() included, and asserts the card actually left. If
 * the middleware is ever dropped again, this is the test that fails.
 */
class TeamsCardDeferralOverHttpTest extends TestCase
{
    private const DEVICES = 'https://prod-1.westus.logic.azure.com/workflows/devices/triggers/manual/paths/invoke';

    public function test_an_api_checkin_posts_its_card_after_the_response()
    {
        Mail::fake();
        Http::fake([self::DEVICES => Http::response('', 202)]);
        config()->set('ecu.teams', [
            'enabled' => true,
            'timeout' => 8,
            'channels' => ['default' => '', 'devices' => self::DEVICES],
        ]);

        $asset = Asset::factory()->laptopMbp()->assignedToUser()->create();

        $this->actingAsForApi(User::factory()->superuser()->create())
            ->postJson(route('api.asset.checkin', $asset->id), ['note' => 'deferred over http'])
            ->assertOk();

        Http::assertSent(fn ($request) => $request->url() === self::DEVICES
            && $request['attachments'][0]['content']['body'][0]['text'] !== '');
    }
}

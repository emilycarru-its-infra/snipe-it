<?php

namespace Tests\Feature\Notifications\Teams;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\PostsThroughRelay;
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
    use PostsThroughRelay;

    public function test_an_api_checkin_posts_its_card_after_the_response()
    {
        Mail::fake();
        $this->fakeRelay();

        $asset = Asset::factory()->laptopMbp()->assignedToUser()->create();

        $this->actingAsForApi(User::factory()->superuser()->create())
            ->postJson(route('api.asset.checkin', $asset->id), ['note' => 'deferred over http'])
            ->assertOk();

        $this->assertStringContainsString('checked in', $this->postedCards()[0]['body'][0]['items'][0]['text']);
    }
}

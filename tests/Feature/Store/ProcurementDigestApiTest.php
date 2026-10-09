<?php

namespace Tests\Feature\Store;

use App\Models\StoreOrder;
use App\Models\User;
use Tests\Support\PostsThroughRelay;
use Tests\TestCase;

/**
 * Running the procurement digest on demand over the API.
 */
class ProcurementDigestApiTest extends TestCase
{
    use PostsThroughRelay;

    public function test_posts_the_digest_now(): void
    {
        $this->fakeRelay();
        StoreOrder::create(['user_id' => User::factory()->create()->id, 'status' => 'pending']);

        $this->actingAsForApi(User::factory()->superuser()->create())
            ->postJson(route('api.procurement.actions-digest'))
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('dry_run', false);

        $this->assertSame(['Procurement'], $this->postedChannels());
    }

    public function test_a_dry_run_lists_without_posting(): void
    {
        $this->fakeRelay();
        $order = StoreOrder::create(['user_id' => User::factory()->create()->id, 'status' => 'pending']);

        $response = $this->actingAsForApi(User::factory()->superuser()->create())
            ->postJson(route('api.procurement.actions-digest'), ['dry_run' => true])
            ->assertOk()
            ->assertJsonPath('dry_run', true);

        $this->assertStringContainsString($order->reference(), $response->json('output'));
        $this->assertSame([], $this->postedChannels());
    }

    public function test_needs_procurement_edit(): void
    {
        $this->actingAsForApi(User::factory()->create())
            ->postJson(route('api.procurement.actions-digest'))
            ->assertForbidden();
    }
}

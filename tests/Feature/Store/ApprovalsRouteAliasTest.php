<?php

namespace Tests\Feature\Store;

use App\Models\User;
use Tests\TestCase;

/**
 * The approvals page moved from /procurement/queue, and the old name is
 * kept resolving on purpose: it is referenced from the top navigation, so
 * a stale route('procurement.queue') anywhere would throw on every page
 * rather than break one link — and several branches are in flight.
 */
class ApprovalsRouteAliasTest extends TestCase
{
    public function test_the_old_route_name_still_resolves_and_redirects(): void
    {
        $this->assertStringEndsWith('/procurement/queue', route('procurement.queue'));
        $this->assertStringEndsWith('/procurement/approvals', route('procurement.approvals'));

        $this->actingAs(User::factory()->superuser()->create())
            ->get('/procurement/queue')
            ->assertRedirect('/procurement/approvals');
    }

    /** No filter means everything, rather than a silent default. */
    public function test_the_page_defaults_to_showing_every_status(): void
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('procurement.approvals'))
            ->assertOk()
            ->assertSee(trans('admin/store/general.queue_status_all'), false)
            ->assertSee(trans('admin/store/general.queue_status_approved'), false);
    }
}

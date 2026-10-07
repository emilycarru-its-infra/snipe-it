<?php

namespace Tests\Feature\Orders;

use App\Enums\ActionType;
use App\Mail\OrderEtaRequestMail;
use App\Models\Actionlog;
use App\Models\Order;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Laravel\Passport\Passport;

/**
 * "What is the ETA for order X?" — to the reps, the team copied, from the
 * order page and the API alike. Both run through OrderEtaRequest, so these
 * pin the parts that must not drift: who it reaches, what it lists, and that
 * only a real send is recorded.
 */
class OrderEtaRequestTest extends VendorOrderTestCase
{
    private function placedOrder(): Order
    {
        $order = $this->vendorOrder(['vendor_order_number' => 'VEND-1001', 'vendor_sent_at' => now()->subWeeks(6)]);
        config(['ecu.device_team_emails' => 'team@example.edu']);

        return $order;
    }

    public function test_the_api_asks_the_reps_and_copies_the_team_and_the_sender()
    {
        Mail::fake();
        $order = $this->placedOrder();
        $sender = $this->procurement();
        Passport::actingAs($sender);

        $this->postJson(route('api.orders.eta-request', $order->id))
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('payload.to', ['rep1@cdw.ca', 'rep2@cdw.ca'])
            ->assertJsonPath('payload.reference', 'VEND-1001')
            ->assertJsonPath('payload.eta_request_count', 1);

        Mail::assertSent(OrderEtaRequestMail::class, function (OrderEtaRequestMail $mail) use ($sender) {
            return $mail->hasTo('rep1@cdw.ca')
                && $mail->hasTo('rep2@cdw.ca')
                && $mail->hasCc('team@example.edu')
                && $mail->hasCc(strtolower($sender->email))
                && $mail->hasReplyTo($sender->email)
                && $mail->envelope()->subject === 'What is the ETA for order VEND-1001?';
        });

        $order->refresh();
        $this->assertNotNull($order->eta_requested_at);
        $this->assertSame(1, $order->eta_request_count);
        $this->assertTrue(Actionlog::where('item_type', Order::class)->where('item_id', $order->id)
            ->where('action_type', ActionType::EtaRequested->value)->exists());

        // The second chase reads as a second chase.
        $this->postJson(route('api.orders.eta-request', $order->id))->assertJsonPath('payload.eta_request_count', 2);
        $this->getJson(route('api.orders.show', $order->id))->assertJsonPath('eta_request_count', 2);
    }

    public function test_only_lines_still_to_arrive_are_listed()
    {
        Mail::fake();
        // Rendered outside a send, the mail layout needs a signed-in user, as every real send has.
        $this->actingAs($this->procurement());
        $order = $this->placedOrder();
        $order->items()->create([
            'description' => 'Already delivered cart', 'quantity' => 1, 'unit_cost' => 10, 'received_at' => now(),
        ]);

        $html = (new OrderEtaRequestMail($order->fresh(['items', 'supplier', 'purchaseOrder'])))->render();

        $this->assertStringContainsString('MacBook Air', $html);
        $this->assertStringNotContainsString('Already delivered cart', $html);
    }

    public function test_a_test_goes_to_the_sender_alone_and_records_nothing()
    {
        Mail::fake();
        $order = $this->placedOrder();
        $sender = $this->procurement();
        Passport::actingAs($sender);

        $this->postJson(route('api.orders.eta-request', $order->id), ['test' => true])
            ->assertOk()->assertJsonPath('payload.to', [$sender->email]);

        Mail::assertSent(OrderEtaRequestMail::class, fn ($mail) => $mail->hasTo($sender->email) && ! $mail->hasTo('rep1@cdw.ca'));
        $this->assertNull($order->fresh()->eta_requested_at);
    }

    public function test_the_caller_can_name_the_recipients_and_add_a_note()
    {
        Mail::fake();
        $order = $this->placedOrder();
        $copied = User::factory()->create(['email' => 'buyer@example.edu']);
        Passport::actingAs($this->procurement());

        $this->postJson(route('api.orders.eta-request', $order->id), [
            'to' => ['am@vendor.example'],
            'cc_users' => [$copied->id],
            'subject' => 'Where is VEND-1001?',
            'note' => 'People are waiting on these.',
        ])->assertOk()->assertJsonPath('payload.to', ['am@vendor.example']);

        Mail::assertSent(OrderEtaRequestMail::class, function ($mail) {
            return $mail->hasTo('am@vendor.example') && ! $mail->hasTo('rep1@cdw.ca')
                && $mail->hasCc('buyer@example.edu')
                && $mail->envelope()->subject === 'Where is VEND-1001?'
                && str_contains($mail->render(), 'People are waiting on these.');
        });
    }

    public function test_preview_reports_who_it_would_reach_without_sending()
    {
        Mail::fake();
        $order = $this->placedOrder();
        Passport::actingAs($this->procurement());

        $this->getJson(route('api.orders.eta-request.preview', $order->id))
            ->assertOk()
            ->assertJsonPath('payload.to', ['rep1@cdw.ca', 'rep2@cdw.ca'])
            ->assertJsonPath('payload.subject', 'What is the ETA for order VEND-1001?')
            ->assertJsonPath('payload.error', null);

        Mail::assertNothingSent();
    }

    public function test_nothing_is_sent_for_an_order_that_has_fully_arrived_or_was_cancelled()
    {
        Mail::fake();
        $order = $this->placedOrder();
        Passport::actingAs($this->procurement());

        $order->items()->update(['received_at' => now()]);
        $this->postJson(route('api.orders.eta-request', $order->id))->assertStatus(422);

        $order->items()->update(['received_at' => null]);
        $order->forceFill(['status' => 'cancelled'])->save();
        $this->postJson(route('api.orders.eta-request', $order->id))->assertStatus(422);

        Mail::assertNothingSent();
    }

    public function test_an_address_belonging_to_another_lessor_stops_the_send()
    {
        Mail::fake();
        $order = $this->placedOrder();
        Supplier::create(['name' => 'Other Lessor', 'email' => 'leases@lessor-b.example', 'contract_prefixes' => 'ZZ99']);
        Passport::actingAs($this->procurement());

        $this->postJson(route('api.orders.eta-request', $order->id), ['to' => ['ops@lessor-b.example']])
            ->assertStatus(422);

        Mail::assertNothingSent();
    }

    public function test_bulk_sends_one_email_per_order_and_reports_the_ones_that_cannot_go()
    {
        Mail::fake();
        $first = $this->placedOrder();
        $second = $this->vendorOrder(['order_number' => 'P0026041-2'], [], ['po_number' => 'P0026099']);
        $second->items()->update(['received_at' => now()]);
        Passport::actingAs($this->procurement());

        $response = $this->postJson(route('api.orders.eta-request.bulk'), ['ids' => [$first->id, $second->id]])
            ->assertOk();

        $results = collect($response->json('payload.results'))->keyBy('id');
        $this->assertTrue($results[$first->id]['sent']);
        $this->assertFalse($results[$second->id]['sent']);
        Mail::assertSent(OrderEtaRequestMail::class, 1);
    }

    public function test_people_who_cannot_edit_orders_cannot_chase_them()
    {
        Mail::fake();
        $order = $this->placedOrder();
        Passport::actingAs(User::factory()->create());

        $this->postJson(route('api.orders.eta-request', $order->id))->assertForbidden();
        Mail::assertNothingSent();
    }

    public function test_the_order_page_sends_it_from_the_dialog()
    {
        Mail::fake();
        $order = $this->placedOrder();
        $sender = $this->procurement();

        $this->actingAs($sender)
            ->get(route('orders.show', $order->id))
            ->assertOk()
            ->assertSee(trans('admin/orders/general.eta_request_button'));

        $this->actingAs($sender)
            ->post(route('orders.eta-request', $order->id), [
                'to_list' => 'rep1@cdw.ca, am@vendor.example',
                'note' => 'Please confirm the ship date.',
            ])
            ->assertRedirect(route('orders.show', $order->id))
            ->assertSessionHas('success');

        Mail::assertSent(OrderEtaRequestMail::class, fn ($mail) => $mail->hasTo('rep1@cdw.ca') && $mail->hasTo('am@vendor.example'));
        $this->assertSame(1, $order->fresh()->eta_request_count);
    }

    public function test_the_order_list_chases_every_ticked_order()
    {
        Mail::fake();
        $order = $this->placedOrder();
        $sender = $this->procurement();

        $this->actingAs($sender)->get(route('orders.index'))
            ->assertOk()
            ->assertSee(trans('admin/orders/general.eta_request_bulk_button'));

        $this->actingAs($sender)
            ->post(route('orders.bulk.eta-request'), ['ids' => [$order->id]])
            ->assertRedirect(route('orders.index'))
            ->assertSessionHas('success');

        Mail::assertSent(OrderEtaRequestMail::class, 1);
    }

    public function test_the_web_action_is_gated_like_editing_an_order()
    {
        Mail::fake();
        $order = $this->placedOrder();

        $this->actingAs(User::factory()->create())
            ->post(route('orders.eta-request', $order->id))
            ->assertForbidden();

        Mail::assertNothingSent();
    }
}

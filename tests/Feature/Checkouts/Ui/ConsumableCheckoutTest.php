<?php

namespace Tests\Feature\Checkouts\Ui;

use App\Mail\CheckoutConsumableMail;
use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\CheckoutAcceptance;
use App\Models\Consumable;
use App\Models\ConsumableTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ConsumableCheckoutTest extends TestCase
{
    public function test_checking_out_consumable_requires_correct_permission()
    {
        $this->actingAs(User::factory()->create())
            ->post(route('consumables.checkout.store', Consumable::factory()->create()))
            ->assertForbidden();
    }

    public function test_page_renders()
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('consumables.checkout.show', Consumable::factory()->create()->id))
            ->assertOk();
    }

    public function test_validation_when_checking_out_consumable()
    {
        $this->actingAs(User::factory()->checkoutConsumables()->create())
            ->post(route('consumables.checkout.store', Consumable::factory()->create()), [
                // missing assigned_to
            ])
            ->assertSessionHas('error');
    }

    public function test_consumable_must_be_available_when_checking_out()
    {
        $this->actingAs(User::factory()->checkoutConsumables()->create())
            ->post(route('consumables.checkout.store', Consumable::factory()->withoutItemsRemaining()->create()), [
                'assigned_to' => User::factory()->create()->id,
            ])
            ->assertSessionHas('error');
    }

    public function test_consumable_can_be_checked_out()
    {
        $consumable = Consumable::factory()->create();
        $user = User::factory()->create();

        $this->actingAs(User::factory()->checkoutConsumables()->create())
            ->post(route('consumables.checkout.store', $consumable), [
                'assigned_to' => $user->id,
            ]);

        $this->assertTrue($user->consumables->contains($consumable));
        $this->assertHasTheseActionLogs($consumable, ['create', 'checkout']);
    }

    public function test_user_sent_notification_upon_checkout()
    {
        Mail::fake();

        $consumable = Consumable::factory()->create();
        $user = User::factory()->create();

        $this->actingAs(User::factory()->checkoutConsumables()->create())
            ->post(route('consumables.checkout.store', $consumable), [
                'assigned_to' => $user->id,
            ]);

        Mail::assertSent(CheckoutConsumableMail::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email);
        });
    }

    public function test_action_log_created_upon_checkout()
    {
        $consumable = Consumable::factory()->create();
        $actor = User::factory()->checkoutConsumables()->create();
        $user = User::factory()->create();

        $this->actingAs($actor)
            ->post(route('consumables.checkout.store', $consumable), [
                'assigned_to' => $user->id,
                'note' => 'oh hi there',
            ]);

        $this->assertEquals(
            1,
            Actionlog::where([
                'action_type' => 'checkout',
                'target_id' => $user->id,
                'target_type' => User::class,
                'item_id' => $consumable->id,
                'item_type' => Consumable::class,
                'created_by' => $actor->id,
                'note' => 'oh hi there',
            ])->count(),
            'Log entry either does not exist or there are more than expected'
        );
    }

    public function test_consumable_checkout_page_post_is_redirected_if_redirect_selection_is_index()
    {
        $consumable = Consumable::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('consumables.index'))
            ->post(route('consumables.checkout.store', $consumable), [
                'assigned_to' => User::factory()->create()->id,
                'redirect_option' => 'index',
                'assigned_qty' => 1,
            ])
            ->assertStatus(302)
            ->assertRedirect(route('consumables.index'));
    }

    public function test_consumable_checkout_page_post_is_redirected_if_redirect_selection_is_item()
    {
        $consumable = Consumable::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('consumables.index'))
            ->post(route('consumables.checkout.store', $consumable), [
                'assigned_to' => User::factory()->create()->id,
                'redirect_option' => 'item',
                'assigned_qty' => 1,
            ])
            ->assertStatus(302)
            ->assertRedirect(route('consumables.show', $consumable));
    }

    public function test_consumable_checkout_page_post_is_redirected_if_redirect_selection_is_target()
    {
        $user = User::factory()->create();
        $consumable = Consumable::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('components.index'))
            ->post(route('consumables.checkout.store', $consumable), [
                'assigned_to' => $user->id,
                'redirect_option' => 'target',
                'assigned_qty' => 1,
            ])
            ->assertStatus(302)
            ->assertRedirect(route('users.show', $user));
    }

    public function test_quantity_stored_in_action_log()
    {
        $consumable = Consumable::factory()->create(['qty' => 3]);
        $user = User::factory()->create();

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->from(route('components.index'))
            ->post(route('consumables.checkout.store', $consumable), [
                'assigned_to' => $user->id,
                'redirect_option' => 'target',
                'checkout_qty' => 2,
            ]);

        $this->assertDatabaseHas('action_logs', [
            'action_type' => 'checkout',
            'target_id' => $user->id,
            'target_type' => User::class,
            'item_id' => $consumable->id,
            'item_type' => Consumable::class,
            'quantity' => 2,
            'created_by' => $admin->id,
        ]);
    }

    public function test_consumable_checkout_page_post_redirects_to_signature_page_when_sign_in_place_is_checked()
    {
        $targetUser = User::factory()->create();
        $consumable = Consumable::factory()->requiringAcceptance()->create();

        $response = $this->actingAs(User::factory()->admin()->create())
            ->from(route('consumables.checkout.show', $consumable))
            ->post(route('consumables.checkout.store', $consumable), [
                'assigned_to' => $targetUser->id,
                'redirect_option' => 'index',
                'checkout_qty' => 2,
                'sign_in_place' => 1,
            ]);

        $acceptance = CheckoutAcceptance::query()
            ->where('checkoutable_type', Consumable::class)
            ->where('checkoutable_id', $consumable->id)
            ->where('assigned_to_id', $targetUser->id)
            ->pending()
            ->latest()
            ->first();

        $this->assertNotNull($acceptance);
        $this->assertEquals(2, $acceptance->qty);

        $response->assertStatus(302)
            ->assertRedirect(route('account.accept.item', $acceptance));
    }

    public function test_consumable_checkout_stores_sign_in_place_preference_in_session()
    {
        $targetUser = User::factory()->create();
        $consumable = Consumable::factory()->create();

        $response = $this->actingAs(User::factory()->admin()->create())
            ->post(route('consumables.checkout.store', $consumable), [
                'assigned_to' => $targetUser->id,
                'redirect_option' => 'index',
                'checkout_qty' => 1,
                'sign_in_place' => 1,
            ]);

        $response->assertSessionHas('sign_in_place', true);
    }

    public function test_checkout_to_printer_with_gl_code_records_a_gl_transaction()
    {
        $consumable = Consumable::factory()->create(['purchase_cost' => 120.00]);
        $printer = Asset::factory()->create();
        $printer->update(['gl_code' => '6100-200-3300']);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('consumables.checkout.store', $consumable), [
                'checkout_to_type' => 'asset',
                'assigned_asset' => $printer->id,
                'checkout_qty' => 3,
                'redirect_option' => 'index',
            ]);

        $txn = ConsumableTransaction::where('consumable_id', $consumable->id)->first();

        $this->assertNotNull($txn, 'a GL transaction should be recorded');
        $this->assertEquals($printer->id, $txn->asset_id);
        $this->assertEquals('6100-200-3300', $txn->gl_code);
        $this->assertEquals(3, $txn->quantity);
        $this->assertEquals(120.00, (float) $txn->unit_cost);
        $this->assertEquals(360.00, (float) $txn->total_cost);
        $this->assertEquals(ConsumableTransaction::STATUS_DRAFT, $txn->status);
        $this->assertEquals(ConsumableTransaction::fiscalYearFor(now()), $txn->fiscal_year);
    }

    public function test_checkout_to_printer_without_gl_code_records_no_gl_transaction()
    {
        $consumable = Consumable::factory()->create();
        $printer = Asset::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('consumables.checkout.store', $consumable), [
                'checkout_to_type' => 'asset',
                'assigned_asset' => $printer->id,
                'checkout_qty' => 1,
                'redirect_option' => 'index',
            ]);

        $this->assertEquals(0, ConsumableTransaction::where('consumable_id', $consumable->id)->count());
    }

    public function test_checkout_to_user_records_no_gl_transaction()
    {
        $consumable = Consumable::factory()->create();
        $user = User::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('consumables.checkout.store', $consumable), [
                'assigned_to' => $user->id,
                'checkout_qty' => 1,
                'redirect_option' => 'index',
            ]);

        $this->assertEquals(0, ConsumableTransaction::where('consumable_id', $consumable->id)->count());
    }

    public function test_fiscal_year_runs_april_to_march()
    {
        $this->assertEquals('FY2026-27', ConsumableTransaction::fiscalYearFor('2026-04-01'));
        $this->assertEquals('FY2026-27', ConsumableTransaction::fiscalYearFor('2027-03-31'));
        $this->assertEquals('FY2025-26', ConsumableTransaction::fiscalYearFor('2026-03-31'));
    }

    public function test_gl_transaction_toggle_off_skips_recording()
    {
        $consumable = Consumable::factory()->create(['purchase_cost' => 90.00]);
        $printer = Asset::factory()->create();
        $printer->update(['gl_code' => '6100-200-3300']);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('consumables.checkout.store', $consumable), [
                'checkout_to_type' => 'asset',
                'assigned_asset' => $printer->id,
                'checkout_qty' => 1,
                'create_gl_transaction' => 0,
                'redirect_option' => 'index',
            ]);

        // The printer has a GL code, but the checkout opted out — no line.
        $this->assertEquals(0, ConsumableTransaction::where('consumable_id', $consumable->id)->count());
    }

    public function test_custom_gl_code_at_checkout_overrides_the_printer_default()
    {
        $consumable = Consumable::factory()->create(['purchase_cost' => 100.00]);
        $printer = Asset::factory()->create();
        $printer->update(['gl_code' => '6100-DEFAULT']);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('consumables.checkout.store', $consumable), [
                'checkout_to_type' => 'asset',
                'assigned_asset' => $printer->id,
                'checkout_qty' => 1,
                'gl_code' => '6200-CUSTOM',
                'redirect_option' => 'index',
            ]);

        $txn = ConsumableTransaction::where('consumable_id', $consumable->id)->first();
        $this->assertNotNull($txn);
        $this->assertEquals('6200-CUSTOM', $txn->gl_code);
    }

    public function test_custom_gl_code_lets_a_printer_without_a_gl_be_charged()
    {
        $consumable = Consumable::factory()->create(['purchase_cost' => 100.00]);
        $printer = Asset::factory()->create(); // no gl_code

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('consumables.checkout.store', $consumable), [
                'checkout_to_type' => 'asset',
                'assigned_asset' => $printer->id,
                'checkout_qty' => 1,
                'gl_code' => '6300-ONEOFF',
                'redirect_option' => 'index',
            ]);

        $txn = ConsumableTransaction::where('consumable_id', $consumable->id)->first();
        $this->assertNotNull($txn, 'a custom GL code should record a transaction even with no printer GL');
        $this->assertEquals('6300-ONEOFF', $txn->gl_code);
    }
}

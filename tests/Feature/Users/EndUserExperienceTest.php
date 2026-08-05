<?php

namespace Tests\Feature\Users;

use App\Mail\AssetBuyoutRequestMail;
use App\Mail\StoreOrderStatusMail;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\CatalogItem;
use App\Models\Category;
use App\Models\CustomField;
use App\Models\FormEligibility;
use App\Models\Group;
use App\Models\Statuslabel;
use App\Models\StoreOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserAgreement;
use App\Services\FormAccess;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The end-user product: four places in the top bar, a dashboard for a home,
 * and a journey that emails at every step — with the admin chrome gone.
 */
class EndUserExperienceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Asset::flushCatalogColumn();
    }

    private function faculty(): User
    {
        $group = Group::factory()->create(['name' => 'Regular Faculty', 'permissions' => json_encode([])]);
        FormEligibility::create(['form_slug' => 'faculty-program', 'group_id' => $group->id]);
        FormAccess::flush();

        $user = User::factory()->create(['activated' => 1, 'first_name' => 'Frida']);
        $user->groups()->attach($group->id);

        return $user;
    }

    /** A faculty member whose program application for this renewal year is in. */
    private function applied(User $user): User
    {
        UserAgreement::create([
            'agreement_type' => 'pickup',
            'user_id' => $user->id,
            'lifecycle_stage' => 'quoted',
            'payment_method' => 'pay_in_full',
            'terms_accepted_at' => now(),
        ]);

        return $user;
    }

    /** Stamp an asset's Catalog custom field (creating the field on first use). */
    private function tagCatalog(Asset $asset, string $value): void
    {
        $field = CustomField::where('name', 'Catalog')->first()
            ?? CustomField::factory()->create(['name' => 'Catalog']);
        Asset::flushCatalogColumn();
        $asset->forceFill([$field->db_column => $value])->saveQuietly();
    }

    private function laptopFor(User $user, array $overrides = []): Asset
    {
        $category = Category::factory()->create(['name' => 'Laptop']);
        $model = AssetModel::factory()->create(['category_id' => $category->id]);

        return Asset::factory()->create(array_merge([
            'model_id' => $model->id,
            'assigned_to' => $user->id,
            'assigned_type' => User::class,
            'last_checkout' => now()->subYears(3),
        ], $overrides));
    }

    public function test_an_end_user_is_one_and_an_admin_is_not()
    {
        $this->assertTrue($this->faculty()->isEndUser());
        $this->assertFalse(User::factory()->superuser()->create()->isEndUser());

        $viewer = User::factory()->create(['activated' => 1]);
        $group = Group::factory()->create(['name' => 'Techs', 'permissions' => json_encode(['assets.view' => '1'])]);
        $viewer->groups()->attach($group->id);
        $this->assertFalse($viewer->fresh()->isEndUser());
    }

    public function test_the_top_bar_is_the_whole_navigation_for_an_end_user()
    {
        $user = $this->applied($this->faculty());
        $page = $this->actingAs($user)->get(route('store.index'))->assertOk();

        // The four destinations, in the navbar…
        $page->assertSee('eu-nav', false);
        foreach ([route('store.index'), route('store.orders'), route('my'), route('forms.index')] as $url) {
            $page->assertSee($url, false);
        }

        // …and no sidebar element at all. (The stylesheet still names the
        // class; the assertion is about the markup.)
        $page->assertDontSee('<aside class="main-sidebar"', false);
        $page->assertDontSee('data-toggle="push-menu"', false);
    }

    public function test_faculty_without_a_form_get_no_store_until_they_apply()
    {
        $user = $this->faculty();

        // No application on file: the store and its tabs are a doorway to
        // the form, not a wall.
        $this->actingAs($user)->get(route('store.index'))
            ->assertRedirect(route('forms.show', 'faculty-program'));
        $this->actingAs($user)->get(route('store.orders'))
            ->assertRedirect(route('forms.show', 'faculty-program'));
        $this->actingAs($user)->get(route('my'))->assertOk()
            ->assertDontSee(route('store.index'), false);

        // Ordering is gated the same way — no order appears.
        $this->actingAs($user)->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => 1, 'quantity' => 1]],
        ])->assertRedirect(route('forms.show', 'faculty-program'));
        $this->assertSame(0, StoreOrder::count());

        // Application in: the store opens.
        $this->applied($user);
        $this->actingAs($user)->get(route('store.index'))->assertOk();
    }

    public function test_staff_without_forms_see_no_forms_tab_but_keep_the_store()
    {
        // An end user in no eligible group: no Forms anywhere, Store open.
        $user = User::factory()->create(['activated' => 1]);

        $page = $this->actingAs($user)->get(route('my'))->assertOk();
        $page->assertDontSee(route('forms.index'), false);
        $page->assertSee(route('store.index'), false);
        $this->actingAs($user)->get(route('store.index'))->assertOk();
    }

    public function test_an_admin_keeps_the_sidebar_and_gets_no_end_user_bar()
    {
        $this->actingAs(User::factory()->superuser()->create())->get(route('users.index'))
            ->assertOk()
            ->assertSee('<aside class="main-sidebar"', false)
            ->assertDontSee('eu-nav', false);
    }

    public function test_the_prior_laptop_is_a_laptop_never_a_monitor()
    {
        $user = $this->faculty();
        $laptop = $this->laptopFor($user);

        // A display checked out later must not win the detection — this is
        // the Henry case, where a monitor was offered for "buyout".
        $displayCategory = Category::factory()->create(['name' => 'Display']);
        $displayModel = AssetModel::factory()->create(['category_id' => $displayCategory->id]);
        Asset::factory()->create([
            'model_id' => $displayModel->id,
            'assigned_to' => $user->id,
            'assigned_type' => User::class,
            'last_checkout' => now()->subDays(3),
        ]);

        $this->assertSame($laptop->id, Asset::currentLaptopOf($user->id)?->id);

        $this->actingAs($user)->get(route('forms.show', 'faculty-program'))
            ->assertOk()
            ->assertSee($laptop->asset_tag, false);
    }

    public function test_a_lone_form_opens_directly_instead_of_a_tile_page()
    {
        $this->actingAs($this->faculty())->get(route('forms.index'))
            ->assertRedirect(route('forms.show', 'faculty-program'));
    }

    public function test_the_dashboard_answers_the_lease_question_all_year()
    {
        $user = $this->faculty();
        $end = now()->addYears(2);
        $laptop = $this->laptopFor($user, [
            'asset_tag' => 'A004242',
            'lease_end_date' => $end->format('Y-m-d'),
            'buyout_cost' => 640,
        ]);
        $this->tagCatalog($laptop, 'Faculty');

        // Two years out: no renewal prompt, no tracker — the lease answer
        // rides on the asset row itself, date first, countdown small.
        $this->actingAs($user)->get(route('home'))->assertRedirect(route('my'));
        $page = $this->actingAs($user)->get(route('my'))->assertOk();
        $page->assertSee('A004242', false);
        $page->assertSee($end->format('M j, Y'), false);
        $page->assertSee('days left', false);
        $page->assertSee('640', false);
        $page->assertDontSee('My Dashboard', false);
        $page->assertDontSee('Start the laptop program form', false);
    }

    public function test_every_active_lease_is_listed_and_accessories_sink()
    {
        $user = $this->faculty();
        $first = $this->laptopFor($user, [
            'asset_tag' => 'A001111',
            'lease_end_date' => now()->addMonths(30)->format('Y-m-d'),
        ]);
        $second = Asset::factory()->create([
            'model_id' => $first->model_id,
            'assigned_to' => $user->id,
            'assigned_type' => User::class,
            'asset_tag' => 'A002222',
            'last_checkout' => now()->subYear(),
            'lease_end_date' => now()->addMonths(40)->format('Y-m-d'),
        ]);

        $accessoryCategory = Category::factory()->create(['name' => 'Accessory']);
        $accessoryModel = AssetModel::factory()->create(['category_id' => $accessoryCategory->id]);
        Asset::factory()->create([
            'model_id' => $accessoryModel->id,
            'assigned_to' => $user->id,
            'assigned_type' => User::class,
            'asset_tag' => 'A003333',
            'last_checkout' => now()->subDay(),
        ]);

        $html = $this->actingAs($user)->get(route('my'))->assertOk()->getContent();

        // Both leases carry their dates…
        $this->assertStringContainsString($first->leaseEndDate()->format('M j, Y'), $html);
        $this->assertStringContainsString($second->leaseEndDate()->format('M j, Y'), $html);

        // …and the accessory renders below both laptops despite the newest
        // checkout date.
        $this->assertGreaterThan(strpos($html, 'A002222'), strpos($html, 'A003333'));
        $this->assertGreaterThan(strpos($html, 'A001111'), strpos($html, 'A003333'));
    }

    public function test_an_end_user_requests_a_buyout_on_their_own_leased_machine()
    {
        Mail::fake();

        // Not program-eligible: an eligible member's buyout goes through the
        // faculty-program form instead, so their dashboard hides the button
        // (asserted at the bottom of this test).
        $user = User::factory()->create(['activated' => 1, 'first_name' => 'Frida']);
        $lessor = Supplier::factory()->create(['email' => 'leasing@lessor.example']);
        $laptop = $this->laptopFor($user, [
            'ownership_type' => 'Leased',
            'lease_end_date' => now()->addMonths(18)->format('Y-m-d'),
            'lessor_id' => $lessor->id,
        ]);
        $this->tagCatalog($laptop, 'Faculty');

        // The button is on their dashboard, and it sends the same lessor
        // email an admin's button sends.
        $this->actingAs($user)->get(route('my'))->assertOk()
            ->assertSee('Request a buyout', false);

        $this->actingAs($user)->post(route('my.request-buyout', $laptop->id))
            ->assertRedirect(route('my'));
        Mail::assertSent(AssetBuyoutRequestMail::class,
            fn ($mail) => $mail->hasTo('leasing@lessor.example'));

        // A second click inside the cooldown does not mail the lessor again,
        // and the row now says so instead of showing the button.
        $this->actingAs($user)->post(route('my.request-buyout', $laptop->id))
            ->assertSessionHas('error');
        Mail::assertSentCount(1);
        $this->actingAs($user)->get(route('my'))->assertOk()
            ->assertSee('Buyout requested', false)
            ->assertDontSee('Request a buyout', false);

        // Someone else's machine is a 403, not an email.
        $other = User::factory()->create(['activated' => 1]);
        $this->actingAs($other)->post(route('my.request-buyout', $laptop->id))
            ->assertForbidden();

        // A program-eligible member does not get the button at all — their
        // buyout decision lives inside the faculty-program form.
        $eligible = $this->faculty();
        $laptop2 = $this->laptopFor($eligible, [
            'ownership_type' => 'Leased',
            'lease_end_date' => now()->addMonths(18)->format('Y-m-d'),
            'lessor_id' => $lessor->id,
        ]);
        $this->tagCatalog($laptop2, 'Faculty');
        $this->actingAs($eligible)->get(route('my'))->assertOk()
            ->assertDontSee('Request a buyout', false);
    }

    public function test_the_actions_split_by_catalog_faculty_buyout_staff_refresh()
    {
        Mail::fake();

        $user = User::factory()->create(['activated' => 1, 'first_name' => 'Stan']);
        $lessor = Supplier::factory()->create(['email' => 'leasing@lessor.example']);

        // A Staff-catalog machine: refresh doorway, never the buyout one —
        // even on an active lease with a lessor email.
        $staffMachine = $this->laptopFor($user, [
            'ownership_type' => 'Leased',
            'lease_end_date' => now()->addMonths(18)->format('Y-m-d'),
            'lessor_id' => $lessor->id,
        ]);
        $this->tagCatalog($staffMachine, 'Staff');

        $page = $this->actingAs($user)->get(route('my'))->assertOk();
        $page->assertSee('Request early refresh', false);
        $page->assertSee(route('store.index', ['refresh' => $staffMachine->id]), false);
        $page->assertDontSee('Request a buyout', false);

        // The doorway lands in the store with the machine named and the GL
        // question asked; a colleague's asset id resolves to no context.
        $store = $this->actingAs($user)->get(route('store.index', ['refresh' => $staffMachine->id]))->assertOk();
        $store->assertSee('Early refresh of your', false);
        $store->assertSee('GL Code', false);

        $colleague = User::factory()->create(['activated' => 1]);
        $colleagueMachine = $this->laptopFor($colleague);
        $this->tagCatalog($colleagueMachine, 'Staff');
        $this->actingAs($user)->get(route('store.index', ['refresh' => $colleagueMachine->id]))
            ->assertOk()
            ->assertDontSee('Early refresh of your', false);

        // Placing the order records the machine and the GL code, and /my
        // swaps the button for the requested note while the order is open.
        $item = CatalogItem::create([
            'name' => 'MacBook Air | 13" | M5', 'family' => 'MacBook Air', 'category' => 'Laptops',
            'product_type' => 'standard', 'vendor_sku' => '9094662', 'unit_cost' => 2100,
            'price_type' => 'quoted', 'show_in_store' => true, 'model_id' => $staffMachine->model_id,
        ]);
        $this->actingAs($user)->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 1]],
            'refresh_asset_id' => $staffMachine->id,
            'gl_code' => '12-345-6789',
        ])->assertRedirect(route('store.orders'));

        $order = StoreOrder::orderByDesc('id')->first();
        $this->assertSame($staffMachine->id, (int) $order->refresh_asset_id);
        $this->assertSame('12-345-6789', $order->gl_code);

        $this->actingAs($user)->get(route('my'))->assertOk()
            ->assertSee('Refresh requested', false)
            ->assertDontSee(route('store.index', ['refresh' => $staffMachine->id]), false);

        // A buyout POST against a staff machine is refused outright.
        $this->actingAs($user)->post(route('my.request-buyout', $staffMachine->id))
            ->assertSessionHas('error');
        Mail::assertNotSent(AssetBuyoutRequestMail::class);

        // A Faculty machine never engages the refresh context: the posted
        // id and GL code are quietly dropped, not stored.
        $facultyMachine = Asset::factory()->create([
            'model_id' => $staffMachine->model_id,
            'assigned_to' => $user->id,
            'assigned_type' => User::class,
            'last_checkout' => now()->subYears(2),
        ]);
        $this->tagCatalog($facultyMachine, 'Faculty');
        $this->actingAs($user)->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 1]],
            'refresh_asset_id' => $facultyMachine->id,
            'gl_code' => '99-999-9999',
        ])->assertRedirect(route('store.orders'));

        $second = StoreOrder::orderByDesc('id')->first();
        $this->assertNull($second->refresh_asset_id);
        $this->assertNull($second->gl_code);
    }

    public function test_the_dashboard_opens_the_door_at_renewal_time()
    {
        $user = $this->faculty();
        $this->laptopFor($user, ['lease_end_date' => now()->addMonths(3)->format('Y-m-d')]);

        $this->actingAs($user)->get(route('my'))
            ->assertOk()
            ->assertSee('Lease renewal', false)
            ->assertSee('Start the laptop program form', false);
    }

    public function test_the_tracker_walks_the_seven_steps()
    {
        $user = $this->applied($this->faculty());
        $model = AssetModel::factory()->create();
        $item = CatalogItem::create([
            'name' => 'MacBook Air | 13" | M5', 'family' => 'MacBook Air', 'category' => 'Laptops',
            'product_type' => 'standard', 'vendor_sku' => '9094662', 'unit_cost' => 2100,
            'price_type' => 'quoted', 'show_in_store' => true, 'model_id' => $model->id,
        ]);

        $this->actingAs($user)->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 1]],
        ]);

        // Order placed: form and order done, processing is now.
        $page = $this->actingAs($user)->get(route('my'))->assertOk();
        $page->assertSee('Ready for pick up', false);
        $page->assertSee('ecu-chev done', false);
        $page->assertSee('ecu-chev now', false);

        // The asset moves through inventory: the tail steps light up.
        $order = StoreOrder::first();
        $order->update(['status' => 'ordered', 'shipped_at' => now(), 'arrived_at' => now()]);
        $provisioned = Asset::where('order_number', $order->reference())->first();
        $ready = Statuslabel::factory()->create(['name' => 'New (Provisioned)', 'pending' => 1,
            'archived' => 0, 'deployable' => 0]);
        $provisioned->update(['status_id' => $ready->id, 'serial' => 'C02NEW']);

        $this->actingAs($user)->get(route('my'))->assertOk()
            ->assertSee($provisioned->asset_tag, false);
    }

    public function test_my_is_the_front_door_and_keeps_a_way_through_to_the_old_profile()
    {
        $user = $this->faculty();

        // Every "My Assets" doorway lands on /my — the old tabbed profile
        // now redirects there rather than existing beside it.
        $this->actingAs($user)->get(route('my'))->assertOk();
        $this->actingAs($user)->get(route('view-assets'))->assertRedirect(route('my'));

        // An admin's sidebar points at the same place, not the old page.
        $admin = User::factory()->superuser()->create();
        $this->actingAs($admin)->get(route('my'))->assertOk()
            ->assertSee('href="'.route('my').'"', false);
    }

    public function test_inventory_transitions_email_the_requester()
    {
        Mail::fake();

        $user = $this->applied($this->faculty());
        $model = AssetModel::factory()->create();
        $item = CatalogItem::create([
            'name' => 'MacBook Air | 13" | M5', 'family' => 'MacBook Air', 'category' => 'Laptops',
            'product_type' => 'standard', 'vendor_sku' => '9094662', 'unit_cost' => 2100,
            'price_type' => 'quoted', 'show_in_store' => true, 'model_id' => $model->id,
        ]);

        $this->actingAs($user)->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 1]],
        ]);

        $asset = Asset::where('order_number', StoreOrder::first()->reference())->first();

        $inventoried = Statuslabel::factory()->create(['name' => 'New (Inventoried)', 'pending' => 1,
            'archived' => 0, 'deployable' => 0]);
        $asset->update(['status_id' => $inventoried->id]);
        Mail::assertSent(StoreOrderStatusMail::class, fn ($mail) => $mail->event === 'inventoried'
            && $mail->hasTo($user->email));

        $ready = Statuslabel::factory()->create(['name' => 'New (Provisioned)', 'pending' => 1,
            'archived' => 0, 'deployable' => 0]);
        $asset->update(['status_id' => $ready->id]);
        Mail::assertSent(StoreOrderStatusMail::class, fn ($mail) => $mail->event === 'ready'
            && $mail->hasTo($user->email));
    }
}

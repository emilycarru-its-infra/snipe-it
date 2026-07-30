<?php

namespace Tests\Feature\Users;

use App\Mail\AssetBuyoutRequestMail;
use App\Mail\StoreOrderStatusMail;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\CatalogItem;
use App\Models\Category;
use App\Models\FormEligibility;
use App\Models\Group;
use App\Models\Statuslabel;
use App\Models\StoreOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\FormAccess;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The end-user product: four places in the top bar, a dashboard for a home,
 * and a journey that emails at every step — with the admin chrome gone.
 */
class EndUserExperienceTest extends TestCase
{
    private function faculty(): User
    {
        $group = Group::factory()->create(['name' => 'Regular Faculty', 'permissions' => json_encode([])]);
        FormEligibility::create(['form_slug' => 'faculty-program', 'group_id' => $group->id]);
        FormAccess::flush();

        $user = User::factory()->create(['activated' => 1, 'first_name' => 'Frida']);
        $user->groups()->attach($group->id);

        return $user;
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
        $page = $this->actingAs($this->faculty())->get(route('store.index'))->assertOk();

        // The four destinations, in the navbar…
        $page->assertSee('eu-nav', false);
        foreach ([route('store.index'), route('store.orders'), route('my'), route('forms.index')] as $url) {
            $page->assertSee($url, false);
        }
        $page->assertSee('My Assets', false);

        // …and no sidebar element at all. (The stylesheet still names the
        // class; the assertion is about the markup.)
        $page->assertDontSee('<aside class="main-sidebar"', false);
        $page->assertDontSee('data-toggle="push-menu"', false);
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
        $this->laptopFor($user, [
            'asset_tag' => 'A004242',
            'lease_end_date' => $end->format('Y-m-d'),
            'buyout_cost' => 640,
        ]);

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

        $user = $this->faculty();
        $lessor = Supplier::factory()->create(['email' => 'leasing@lessor.example']);
        $laptop = $this->laptopFor($user, [
            'ownership_type' => 'Leased',
            'lease_end_date' => now()->addMonths(18)->format('Y-m-d'),
            'lessor_id' => $lessor->id,
        ]);

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
        $user = $this->faculty();
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
        $page->assertSee('eud-chev done', false);
        $page->assertSee('eud-chev now', false);

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

    public function test_inventory_transitions_email_the_requester()
    {
        Mail::fake();

        $user = $this->faculty();
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

<?php

namespace Tests\Feature\Orders;

use App\Models\CatalogItem;
use App\Models\Requisition;
use App\Models\Supplier;
use App\Models\User;
use Tests\TestCase;

/**
 * The fields Colleague asks for when a purchase order is keyed, taken from
 * the issued POs (P0025395, P0025419) rather than guessed.
 */
class RequisitionColleagueFieldsTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    private function catalogItem(): CatalogItem
    {
        return CatalogItem::create([
            'name' => 'Apple iMac 16GB 512GB nano texture',
            'product_type' => 'cto',
            'vendor_sku' => '9219347',
            'unit_cost' => 2658.77,
            'price_type' => 'quoted',
        ]);
    }

    /** A basket shaped like the real P0025395. */
    private function basket(array $overrides = []): array
    {
        $item = $this->catalogItem();

        return array_merge([
            'title' => 'CSI lease refresh',
            'default_gl_number' => '31-00-350010-8236',
            'printer_comments' => "LEASE - PO will be ordered in online eStore. Do not email PO.\nCopy PO to Rod Christiansen.",
            'internal_comments' => 'Waiting on Joshua to confirm the lease schedule number.',
            'items' => [
                [
                    'catalog_item_id' => $item->id,
                    'description' => $item->name,
                    'vendor_sku' => $item->vendor_sku,
                    'quantity' => 33,
                    'unit_cost' => 2658.77,
                ],
            ],
        ], $overrides);
    }

    public function test_comments_gl_and_unit_are_saved()
    {
        $this->actingAs($this->superuser())
            ->post(route('requisitions.store'), $this->basket())
            ->assertRedirect();

        $requisition = Requisition::with('items')->first();

        $this->assertSame('31-00-350010-8236', $requisition->default_gl_number);
        $this->assertStringContainsString('online eStore', $requisition->printer_comments);
        $this->assertStringContainsString('Joshua', $requisition->internal_comments);

        $line = $requisition->items->first();

        // A line with no GL of its own inherits the requisition's.
        $this->assertSame('31-00-350010-8236', $line->gl_number);
        $this->assertSame('EA', $line->unit_of_measure);
        $this->assertEqualsWithDelta(87739.41, $requisition->subtotal(), 0.01);
    }

    public function test_a_line_can_carry_its_own_gl_number()
    {
        $basket = $this->basket();
        $basket['items'][0]['gl_number'] = '31-00-350020-8236';

        $this->actingAs($this->superuser())
            ->post(route('requisitions.store'), $basket)
            ->assertRedirect();

        $this->assertSame('31-00-350020-8236', Requisition::first()->items->first()->gl_number);
    }

    public function test_printer_comments_reach_the_keying_sheet_but_internal_comments_do_not()
    {
        $user = $this->superuser();

        $this->actingAs($user)->post(route('requisitions.store'), $this->basket());

        $requisition = Requisition::first();

        $response = $this->actingAs($user)
            ->get(route('requisitions.print', $requisition->id))
            ->assertOk();

        // The vendor sees the printer comments and the GL coding.
        $response->assertSee('online eStore', false);
        $response->assertSee('31-00-350010-8236', false);

        // The internal note is exactly what must not be typeset onto the PO.
        $response->assertDontSee('Joshua', false);
    }

    public function test_internal_comments_are_visible_on_the_requisition_itself()
    {
        $user = $this->superuser();

        $this->actingAs($user)->post(route('requisitions.store'), $this->basket());

        $this->actingAs($user)
            ->get(route('requisitions.show', Requisition::first()->id))
            ->assertOk()
            ->assertSee('Joshua', false);
    }

    public function test_a_supplier_carries_its_colleague_vendor_id()
    {
        $supplier = Supplier::create(['name' => 'CSI Leasing Canada Ltd', 'colleague_vendor_id' => '0135495']);

        $this->assertSame('0135495', $supplier->fresh()->colleague_vendor_id);
    }

    public function test_reopening_a_draft_restores_the_line_coding()
    {
        $user = $this->superuser();

        $this->actingAs($user)->post(route('requisitions.store'), $this->basket());

        $response = $this->actingAs($user)
            ->get(route('purchase-orders.builder', ['requisition' => Requisition::first()->id]))
            ->assertOk();

        preg_match('/id="pob-basket">(.*?)<\/script>/s', $response->getContent(), $matches);
        $basket = json_decode(html_entity_decode($matches[1] ?? '[]'), true);

        $this->assertSame('31-00-350010-8236', $basket[0]['gl_number']);
        $this->assertSame('EA', $basket[0]['unit_of_measure']);
    }
}

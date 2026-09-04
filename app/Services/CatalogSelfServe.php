<?php

namespace App\Services;

use App\Models\CatalogItem;
use App\Models\CatalogItemRequest;
use App\Models\Manufacturer;
use App\Models\Supplier;
use App\Models\User;

/**
 * Adding to the catalog from a vendor link, without asking anybody.
 *
 * The catalog is curated, and this is the deliberate exception. The thing it
 * replaces was a request in a chat thread that somebody keyed in by hand days
 * later; the person wanting an HDMI switch can now paste its link and order
 * it. Every attempt is recorded, so the curation that still matters happens
 * after the fact, on evidence, instead of standing between a person and a
 * $40 part.
 *
 * Three rules hold the line that speed buys:
 *
 *   the price is an estimate — CDW's page is list, we buy on contract, and a
 *                              row marked quoted would put a wrong number
 *                              into a purchase order
 *   the row says it is self-serve — so the catalog admin can see at a glance
 *                              what arrived this way and curate it
 *   a known SKU is never doubled — the second person to want the same switch
 *                              gets the existing row, not a twin
 */
class CatalogSelfServe
{
    public function __construct(
        private readonly CdwProductLookup $lookup,
        private readonly CatalogImageFetcher $images,
    ) {}

    /**
     * @return array{request: CatalogItemRequest, item: ?CatalogItem, created: bool}
     */
    public function addFromLink(string $url, ?User $user): array
    {
        try {
            $product = $this->lookup->lookup($url);
        } catch (CdwLookupFailed $e) {
            $request = CatalogItemRequest::create([
                'created_by' => $user?->id,
                'url' => $url,
                'vendor_sku' => CdwProductLookup::skuFrom($url),
                'outcome' => CatalogItemRequest::FAILED,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ]);

            return ['request' => $request, 'item' => null, 'created' => false];
        }

        // Somebody already asked for this, or it was always in the catalog.
        // Hand back what exists rather than a second row that splits the
        // orders for one product across two SKUs.
        $existing = CatalogItem::where('vendor_sku', $product['vendor_sku'])->first();

        if ($existing) {
            $request = CatalogItemRequest::create([
                'created_by' => $user?->id,
                'url' => $url,
                'vendor_sku' => $product['vendor_sku'],
                'name' => $product['name'],
                'catalog_item_id' => $existing->id,
                'outcome' => CatalogItemRequest::DUPLICATE,
                'payload' => $product,
            ]);

            return ['request' => $request, 'item' => $existing, 'created' => false];
        }

        $item = $this->build($product, $user);

        if (! $item->save()) {
            $request = CatalogItemRequest::create([
                'created_by' => $user?->id,
                'url' => $url,
                'vendor_sku' => $product['vendor_sku'],
                'name' => $product['name'],
                'outcome' => CatalogItemRequest::FAILED,
                'error' => mb_substr((string) $item->getErrors(), 0, 500),
                'payload' => $product,
            ]);

            return ['request' => $request, 'item' => null, 'created' => false];
        }

        $this->images->attach($item, $product['image_url'] ?? null);

        $request = CatalogItemRequest::create([
            'created_by' => $user?->id,
            'url' => $url,
            'vendor_sku' => $product['vendor_sku'],
            'name' => $product['name'],
            'catalog_item_id' => $item->id,
            'outcome' => CatalogItemRequest::CREATED,
            'payload' => $product,
        ]);

        return ['request' => $request, 'item' => $item, 'created' => true];
    }

    /** @param array<string, mixed> $product */
    private function build(array $product, ?User $user): CatalogItem
    {
        $item = new CatalogItem;
        $item->name = $product['name'];
        $item->category = $product['category'];
        $item->subcategory = $product['vendor_category'] ?? null;
        $item->vendor_sku = $product['vendor_sku'];
        $item->mfr_part_number = $product['mfr_part_number'] ?? null;
        $item->product_type = 'standard';

        // List, not contract. The estimate badge is the whole point: nobody
        // should key this number into Colleague believing it was quoted.
        $item->price_type = 'estimate';
        $item->estimated_cost = $product['list_price'] ?? null;
        $item->currency = 'CAD';
        $item->source = 'CDW.ca product page';
        $item->source_url = $product['url'];

        $item->supplier_id = Supplier::where('name', 'like', 'CDW%')->value('id');
        $item->manufacturer_id = $this->manufacturerId($product['manufacturer'] ?? null);

        $item->is_active = true;
        $item->show_in_store = true;
        $item->self_serve = true;
        $item->store_sort = 0;
        $item->created_by = $user?->id;

        return $item;
    }

    /**
     * Match a manufacturer, never invent one. The manufacturer list is its
     * own curated thing, and a brand string off a retail page ("Apple iPad",
     * "AddOn Networks") would litter it with near-duplicates.
     */
    private function manufacturerId(?string $name): ?int
    {
        if (! $name) {
            return null;
        }

        return Manufacturer::whereRaw('LOWER(name) = ?', [strtolower($name)])->value('id');
    }

}

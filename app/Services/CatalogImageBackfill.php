<?php

namespace App\Services;

use App\Models\CatalogItem;
use Illuminate\Support\Collection;

/**
 * Give the catalog rows that have no picture the vendor's own.
 *
 * The storefront renders accessories as a grid of tiles, and a row with no
 * image is a blank one — which is most of the accessories shelf. Every one of
 * those rows already carries the vendor's SKU, and the vendor publishes a
 * photo against it, so the picture is a lookup rather than a piece of work
 * anybody should be doing by hand.
 *
 * The part number is the guard. Where a row already holds one, the image is
 * only attached if the vendor's page agrees — a mistyped SKU should fail
 * loudly rather than quietly dress a row in another product's photo.
 */
class CatalogImageBackfill
{
    public function __construct(
        private readonly CdwProductLookup $lookup,
        private readonly CatalogImageFetcher $images,
    ) {}

    /**
     * @param  array<int, int>  $ids  Limit to these rows; empty means every
     *                                row with a SKU and no image.
     * @return array<string, mixed>
     */
    public function run(array $ids = [], bool $dryRun = false): array
    {
        $rows = $this->candidates($ids);

        $report = [
            'considered' => $rows->count(),
            'attached' => 0,
            'skipped' => 0,
            'failed' => 0,
            'dry_run' => $dryRun,
            'items' => [],
        ];

        foreach ($rows as $item) {
            $outcome = $this->one($item, $dryRun);

            $report['items'][] = $outcome;
            $report[$outcome['result'] === 'attached' ? 'attached' : ($outcome['result'] === 'failed' ? 'failed' : 'skipped')]++;
        }

        return $report;
    }

    /** @return Collection<int, CatalogItem> */
    private function candidates(array $ids): Collection
    {
        return CatalogItem::query()
            ->whereNull('image')
            ->whereNotNull('vendor_sku')
            ->where('vendor_sku', '!=', '')
            ->when($ids !== [], fn ($query) => $query->whereIn('id', $ids))
            ->orderBy('id')
            ->get();
    }

    /** @return array<string, mixed> */
    private function one(CatalogItem $item, bool $dryRun): array
    {
        $row = [
            'id' => $item->id,
            'name' => $item->name,
            'vendor_sku' => $item->vendor_sku,
        ];

        try {
            $product = $this->lookup->lookup($this->urlFor($item->vendor_sku));
        } catch (CdwLookupFailed $e) {
            return $row + ['result' => 'failed', 'reason' => $e->getMessage()];
        }

        // The row's own part number, against the page's. A SKU that resolves
        // to a different product is a data error worth reporting, not an
        // image worth attaching.
        $ours = $this->normalise($item->mfr_part_number);
        $theirs = $this->normalise($product['mfr_part_number'] ?? null);

        if ($ours !== null && $theirs !== null && $ours !== $theirs) {
            return $row + [
                'result' => 'failed',
                'reason' => "part number mismatch: catalog has {$item->mfr_part_number}, the vendor's page says {$product['mfr_part_number']}",
            ];
        }

        if (! $this->images->isVendorImage($product['image_url'] ?? null)) {
            return $row + ['result' => 'skipped', 'reason' => 'no usable product image on the page'];
        }

        if ($dryRun) {
            return $row + ['result' => 'attached', 'image_url' => $product['image_url'], 'written' => false];
        }

        $stored = $this->images->attach($item, $product['image_url']);

        if ($stored === null) {
            return $row + ['result' => 'failed', 'reason' => 'the image could not be fetched'];
        }

        return $row + ['result' => 'attached', 'image' => $stored, 'written' => true];
    }

    /**
     * The vendor canonicalises the slug from the SKU, so the slug can be
     * anything and only the number has to be right.
     */
    private function urlFor(string $sku): string
    {
        return 'https://www.cdw.ca/product/x/'.rawurlencode($sku);
    }

    private function normalise(?string $partNumber): ?string
    {
        if ($partNumber === null) {
            return null;
        }

        $value = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $partNumber) ?? '');

        return $value === '' ? null : $value;
    }
}

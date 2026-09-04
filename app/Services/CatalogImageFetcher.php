<?php

namespace App\Services;

use App\Models\CatalogItem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Copies a vendor's product photo onto a catalog row.
 *
 * Lifted out of the self-serve path so the backfill can use the same code:
 * one place that knows which hosts a picture may come from, and one place
 * that knows a picture is never worth failing a save over.
 */
class CatalogImageFetcher
{
    /** The vendor's image CDNs — the only hosts a product image may come from. */
    public const HOSTS = ['webobjects2.cdw.com', 'webobjects.cdw.com', 'www.cdw.ca', 'cdw.ca'];

    /**
     * Fetch the image at $url and attach it to $item. Returns the stored
     * filename, or null when nothing was attached.
     */
    public function attach(CatalogItem $item, ?string $url): ?string
    {
        // The URL comes out of a page's own markup, so it is content and not a
        // constant: whoever controls that page chooses where this server sends
        // its next request. An https:// prefix is no protection at all —
        // https://169.254.169.254/ has one — so the host is checked against the
        // vendor's image CDNs and nothing else, and redirects are not followed
        // because a redirect is how a check made once is walked past.
        if (! $this->isVendorImage($url)) {
            return null;
        }

        try {
            $response = Http::timeout(15)->withoutRedirecting()->get($url);

            if (! $response->successful()) {
                return null;
            }

            $extension = $this->extensionFor((string) $response->header('Content-Type'));

            if ($extension === null) {
                return null;
            }

            $name = 'catalog-'.$item->id.'-'.substr(sha1((string) $url), 0, 8).'.'.$extension;

            Storage::disk('public')->put('catalog/'.$name, $response->body());

            $item->image = $name;
            $item->saveQuietly();

            return $name;
        } catch (\Throwable $e) {
            // A row with no picture is still a row somebody can order.
            Log::warning('CATALOG.IMAGE fetch failed', [
                'catalog_item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function isVendorImage(?string $url): bool
    {
        if ($url === null) {
            return false;
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            return false;
        }

        if (strtolower($parts['scheme'] ?? '') !== 'https' || isset($parts['port'])) {
            return false;
        }

        return in_array(strtolower($parts['host']), self::HOSTS, true);
    }

    private function extensionFor(string $contentType): ?string
    {
        $type = strtolower($contentType);

        return match (true) {
            str_contains($type, 'png') => 'png',
            str_contains($type, 'webp') => 'webp',
            str_contains($type, 'gif') => 'gif',
            str_contains($type, 'jpeg'), str_contains($type, 'jpg') => 'jpg',
            default => null,
        };
    }
}

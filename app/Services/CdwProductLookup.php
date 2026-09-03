<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Reads a CDW.ca product page into the fields a catalog row needs.
 *
 * Everything here comes off the page as the vendor publishes it: the
 * manufacturer's number is microdata (`itemprop="mpn"`), and the rest sits in
 * the `cdwTagManagementData` block the page writes for its own analytics —
 * the EDC, the name, the list price, the brand and the two category levels.
 * That block is server-rendered even when the caller is flagged as a bot,
 * which the storefront's outbound address will be.
 *
 * What it deliberately does not do is believe the price. CDW's page shows
 * list; we buy on contract, so the number becomes an *estimate* and the row
 * is never marked quoted. The quote that eventually comes back is what
 * corrects it, through the normal write-back.
 */
class CdwProductLookup
{
    /** Hosts a link is accepted from. */
    public const HOSTS = ['cdw.ca', 'www.cdw.ca'];

    /**
     * CDW's taxonomy is not ours. Theirs is a retail tree ("Keyboards &
     * Mice", "Monitor Accessories"); ours is seven buckets sized for how the
     * estate is managed. Map what we can and let the rest fall to
     * Accessories, which is what a one-off from a link nearly always is.
     */
    private const CATEGORIES = [
        'notebook computers' => 'Laptops',
        'laptops' => 'Laptops',
        'ultrabooks' => 'Laptops',
        'desktop computers' => 'Desktops',
        'all-in-one computers' => 'Desktops',
        'workstations' => 'Desktops',
        'tablets' => 'Tablets',
        'monitors' => 'Displays',
        'computer monitors' => 'Displays',
        'scanners' => 'Scanners',
        'document scanners' => 'Scanners',
        'memory' => 'Components',
        'hard drives' => 'Components',
        'solid state drives' => 'Components',
        'processors' => 'Components',
        'graphics cards' => 'Components',
    ];

    public function __construct(private readonly int $timeout = 20) {}

    /**
     * Whether this looks like a CDW.ca product link at all, before any
     * network call — a typo should not cost a request to a stranger's site.
     */
    public static function accepts(string $url): bool
    {
        $host = strtolower((string) parse_url(trim($url), PHP_URL_HOST));

        return in_array($host, self::HOSTS, true);
    }

    /**
     * The vendor's own SKU, which is the last path segment of a product URL.
     * CDW canonicalises the slug, so this is the only part that must be right.
     */
    public static function skuFrom(string $url): ?string
    {
        $path = (string) parse_url(trim($url), PHP_URL_PATH);

        return preg_match('#/(\d{5,10})/?$#', $path, $m) ? $m[1] : null;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws CdwLookupFailed
     */
    public function lookup(string $url): array
    {
        $url = trim($url);

        if (! self::accepts($url)) {
            throw new CdwLookupFailed(trans('admin/store/general.catalog_link_not_cdw'));
        }

        if (! self::skuFrom($url)) {
            throw new CdwLookupFailed(trans('admin/store/general.catalog_link_no_sku'));
        }

        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) '
                        .'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml',
                    'Accept-Language' => 'en-CA,en;q=0.9',
                ])
                ->get($url);
        } catch (\Throwable $e) {
            throw new CdwLookupFailed(trans('admin/store/general.catalog_link_unreachable'), previous: $e);
        }

        if (! $response->successful()) {
            throw new CdwLookupFailed(trans('admin/store/general.catalog_link_http', [
                'status' => $response->status(),
            ]));
        }

        return $this->parse($response->body(), $url);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws CdwLookupFailed
     */
    public function parse(string $body, string $url): array
    {
        $tags = $this->tagData($body);

        // The vendor's own id for the product, taken from the page and not
        // from the URL. A search result, a category landing and a 404 dressed
        // as a 200 all have a plausible URL; only a product page says
        // product_id. Trusting the URL here named a catalog row "Search".
        $sku = $this->clean($tags['product_id'] ?? null);
        $name = $this->clean($tags['product_name'] ?? null) ?: $this->title($body);

        if (! $sku || ! $name) {
            throw new CdwLookupFailed(trans('admin/store/general.catalog_link_not_a_product'));
        }

        return [
            'url' => $url,
            'vendor_sku' => $sku,
            'mfr_part_number' => $this->mpn($body),
            'name' => Str::limit($name, 188, ''),
            'list_price' => $this->price($tags['product_price'] ?? null),
            'manufacturer' => $this->clean($tags['product_root_brand_name'] ?? null),
            'category' => $this->category($tags),
            'vendor_category' => $this->clean($tags['webclasscode_level2name'] ?? null),
            'image_url' => $this->clean($tags['product_image'] ?? null),
            'stock_status' => $this->clean($tags['product_stock_status'] ?? null),
        ];
    }

    /**
     * The analytics block, which is single-quoted JavaScript rather than JSON
     * and so is read by pattern rather than decoded.
     *
     * @return array<string, string>
     */
    private function tagData(string $body): array
    {
        if (! preg_match('/window\.cdwTagManagementData\s*=\s*\{(.*?)\};/s', $body, $m)) {
            return [];
        }

        preg_match_all("/'([^']+)'\s*:\s*'((?:[^'\\\\]|\\\\.)*)'/", $m[1], $pairs, PREG_SET_ORDER);

        $data = [];

        foreach ($pairs as $pair) {
            $data[$pair[1]] = stripslashes($pair[2]);
        }

        return $data;
    }

    private function mpn(string $body): ?string
    {
        if (preg_match('/itemprop="mpn"[^>]*>([^<]+)</', $body, $m)) {
            return $this->clean($m[1]);
        }

        // The title carries it too, between the name and the category, for a
        // page whose markup has moved on without the microdata.
        if (preg_match('#<title>.*? - ([A-Z0-9][A-Z0-9./-]{3,30}) - [^<]*</title>#', $body, $m)) {
            return $this->clean($m[1]);
        }

        return null;
    }

    private function title(string $body): ?string
    {
        if (! preg_match('#<title>([^<]+)</title>#', $body, $m)) {
            return null;
        }

        // "Name - MPN - Category - CDW.ca" — keep the name.
        return $this->clean(explode(' - ', html_entity_decode($m[1]))[0]);
    }

    private function price(?string $raw): ?float
    {
        $value = (float) preg_replace('/[^0-9.]/', '', (string) $raw);

        return $value > 0 ? round($value, 2) : null;
    }

    /** @param array<string, string> $tags */
    private function category(array $tags): string
    {
        foreach (['webclasscode_level2name', 'webclasscode_level1name', 'product_category'] as $key) {
            $value = strtolower(trim($this->clean($tags[$key] ?? null) ?? ''));

            if ($value !== '' && isset(self::CATEGORIES[$value])) {
                return self::CATEGORIES[$value];
            }
        }

        return 'Accessories';
    }

    private function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5));

        return $value === '' ? null : $value;
    }
}

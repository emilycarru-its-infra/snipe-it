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
     * How many hops a link may take before we stop following it.
     *
     * Redirects are followed by hand rather than by the HTTP client because
     * the client checks the host once and then goes wherever it is sent. We
     * depend on one redirect — a wrong slug is canonicalised to the right URL
     * — so switching them off is not an option, and following them blindly
     * means a redirect off this vendor's site is a request this server makes
     * to wherever it is pointed. From inside the cloud that includes the
     * instance metadata endpoint, which hands out managed-identity tokens.
     * So every hop is re-checked against the same allowlist as the first.
     */
    private const MAX_HOPS = 4;

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
        $parts = parse_url(trim($url));

        if ($parts === false || ! isset($parts['host'])) {
            return false;
        }

        // Plain http would be a downgrade somebody on the path could steer,
        // and a port says somebody is aiming this at something specific.
        if (strtolower($parts['scheme'] ?? '') !== 'https' || isset($parts['port'])) {
            return false;
        }

        return in_array(strtolower($parts['host']), self::HOSTS, true);
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

        $response = $this->fetch($url);

        return $this->parse($response['body'], $response['url']);
    }

    /**
     * Fetch the page, following redirects one at a time and re-checking the
     * destination of each against the allowlist.
     *
     * @return array{body: string, url: string}
     *
     * @throws CdwLookupFailed
     */
    private function fetch(string $url): array
    {
        for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
            try {
                $response = Http::timeout($this->timeout)
                    ->withoutRedirecting()
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

            if ($response->redirect()) {
                $next = $this->resolve($url, (string) $response->header('Location'));

                // A redirect off the vendor's site is where this stops. It is
                // the same refusal as a link to another site in the first
                // place, and it says so, because to the person who pasted a
                // vendor link that is what happened.
                if ($next === null || ! self::accepts($next)) {
                    throw new CdwLookupFailed(trans('admin/store/general.catalog_link_not_cdw'));
                }

                $url = $next;

                continue;
            }

            if (! $response->successful()) {
                throw new CdwLookupFailed(trans('admin/store/general.catalog_link_http', [
                    'status' => $response->status(),
                ]));
            }

            return ['body' => $response->body(), 'url' => $url];
        }

        throw new CdwLookupFailed(trans('admin/store/general.catalog_link_unreachable'));
    }

    /** Resolve a Location header, which may be relative, against the current URL. */
    private function resolve(string $current, string $location): ?string
    {
        $location = trim($location);

        if ($location === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $parts = parse_url($current);

        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $base = $parts['scheme'].'://'.$parts['host'];

        return str_starts_with($location, '/')
            ? $base.$location
            : $base.'/'.ltrim($location, '/');
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

<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Models\License;
use App\Models\ProductIdentity;
use App\Models\ProductIdentityAlias;
use App\Services\FiscalYear;
use App\Services\ProductIdentity\ProductResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\MessageBag;

/**
 * Admin CRUD for product identities: the products a license covers, and the
 * alias patterns that recognise each one in what endpoints report. Lives
 * under /admin beside license models, so it inherits the superuser gate.
 *
 * Aliases and license links are edited on the product's own form as rows,
 * and saved by replacing the product's set, so the form is the whole truth
 * about a product.
 */
class ProductIdentitiesController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('view', ProductIdentity::class);

        $products = ProductIdentity::withCount(['aliases', 'licenses'])
            ->orderBy('name')
            ->get();

        $unlinked = License::whereDoesntHave('productIdentities')
            ->with('licenseModel')
            ->orderBy('name');
        $unlinkedCount = (clone $unlinked)->count();

        $probe = null;
        if ($request->filled('probe')) {
            $probe = [
                'name'     => (string) $request->input('probe'),
                'platform' => ProductResolver::normalizePlatform($request->input('platform')),
                'result'   => app(ProductResolver::class)->resolve((string) $request->input('probe'), $request->input('platform')),
            ];
        }

        return view('product-identities/index', [
            'products'      => $products,
            'unlinked'      => $unlinked->limit(100)->get(),
            'unlinkedCount' => $unlinkedCount,
            'probe'         => $probe,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', ProductIdentity::class);

        return view('product-identities/edit', $this->formData(new ProductIdentity));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', ProductIdentity::class);

        return $this->save($request, new ProductIdentity, 'create');
    }

    public function show(ProductIdentity $productIdentity): View
    {
        $this->authorize('view', ProductIdentity::class);

        $productIdentity->load(['aliases', 'licenses.contract']);

        return view('product-identities/view', ['item' => $productIdentity]);
    }

    public function edit(ProductIdentity $productIdentity): View
    {
        $this->authorize('update', ProductIdentity::class);

        return view('product-identities/edit', $this->formData($productIdentity->load(['aliases', 'licenses'])));
    }

    public function update(Request $request, ProductIdentity $productIdentity): RedirectResponse
    {
        $this->authorize('update', ProductIdentity::class);

        return $this->save($request, $productIdentity, 'update');
    }

    public function destroy(ProductIdentity $productIdentity): RedirectResponse
    {
        $this->authorize('delete', ProductIdentity::class);

        DB::transaction(function () use ($productIdentity) {
            $productIdentity->aliases()->delete();
            $productIdentity->licenses()->detach();
            $productIdentity->delete();
        });

        return redirect()->route('product-identities.index')
            ->with('success', trans('admin/productidentities/message.delete.success'));
    }

    private function formData(ProductIdentity $item): array
    {
        return [
            'item'        => $item,
            'licenses'    => License::orderBy('name')->get(['id', 'name']),
            'fiscalYears' => Helper::fiscalYearOptions(),
        ];
    }

    private function save(Request $request, ProductIdentity $item, string $action): RedirectResponse
    {
        $item->fill($request->only(['name', 'publisher', 'procurement_name', 'notes']));
        if (! $item->exists) {
            $item->created_by = auth()->id();
        }

        [$aliases, $links, $errors] = $this->rows($request);

        if ($errors->isNotEmpty() || ! $item->isValid()) {
            $errors->merge($item->getErrors());

            return redirect()->back()->withInput()->withErrors($errors);
        }

        DB::transaction(function () use ($item, $aliases, $links) {
            $item->save();
            $item->aliases()->delete();
            foreach ($aliases as $alias) {
                $item->aliases()->create($alias);
            }
            $item->licenses()->detach();
            foreach ($links as $link) {
                $item->licenses()->attach($link['license_id'], ['fiscal_year' => $link['fiscal_year']]);
            }
        });

        return redirect()->route('product-identities.show', $item)
            ->with('success', trans("admin/productidentities/message.{$action}.success"));
    }

    /**
     * The alias and license rows off the form, with blank rows dropped and
     * duplicates collapsed. Error messages echo what was typed, and the form
     * prints them unescaped inside its error markup, so the input is escaped
     * here.
     *
     * @return array{0: array<int, array>, 1: array<int, array>, 2: MessageBag}
     */
    private function rows(Request $request): array
    {
        $errors = new MessageBag;
        $aliases = [];
        $links = [];

        foreach ((array) $request->input('aliases', []) as $i => $row) {
            $pattern = trim((string) ($row['pattern'] ?? ''));
            if ($pattern === '') {
                continue;
            }
            $platform = in_array($row['platform'] ?? 'any', ProductIdentityAlias::PLATFORMS, true) ? $row['platform'] : 'any';
            $matchType = in_array($row['match_type'] ?? 'exact', ProductIdentityAlias::MATCH_TYPES, true) ? $row['match_type'] : 'exact';

            if (mb_strlen($pattern) > 255) {
                $errors->add("aliases.{$i}.pattern", trans('admin/productidentities/message.pattern_too_long'));

                continue;
            }
            if (! ProductIdentityAlias::patternIsValid($matchType, $pattern)) {
                $errors->add("aliases.{$i}.pattern", trans('admin/productidentities/message.bad_regex', ['pattern' => e($pattern)]));

                continue;
            }

            $aliases[$platform.'|'.$matchType.'|'.mb_strtolower($pattern)] = [
                'platform'   => $platform,
                'match_type' => $matchType,
                'pattern'    => $pattern,
            ];
        }

        foreach ((array) $request->input('license_links', []) as $i => $row) {
            $licenseId = (int) ($row['license_id'] ?? 0);
            if ($licenseId === 0) {
                continue;
            }
            if (! License::whereKey($licenseId)->exists()) {
                $errors->add("license_links.{$i}.license_id", trans('admin/productidentities/message.bad_license'));

                continue;
            }

            $rawFy = trim((string) ($row['fiscal_year'] ?? ''));
            $fy = $rawFy === '' ? null : FiscalYear::normalize($rawFy);
            if ($rawFy !== '' && $fy === null) {
                $errors->add("license_links.{$i}.fiscal_year", trans('admin/productidentities/message.bad_fiscal_year', ['fy' => e($rawFy)]));

                continue;
            }

            $links[$licenseId.'|'.($fy ?? '*')] = ['license_id' => $licenseId, 'fiscal_year' => $fy];
        }

        return [array_values($aliases), array_values($links), $errors];
    }
}

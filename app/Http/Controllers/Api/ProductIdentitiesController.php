<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductIdentity;
use App\Services\ProductIdentity\ProductResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read side of the product identity catalogue, for whatever feeds observed
 * application usage into Snipe-IT: list the catalogue, or post a batch of
 * observed applications and get back which products and licenses they
 * resolve to, and which high-usage ones nothing claims.
 */
class ProductIdentitiesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('view', ProductIdentity::class);

        $query = ProductIdentity::with(['aliases', 'licenses'])->orderBy('name');
        if ($request->filled('search')) {
            $term = '%'.$request->input('search').'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)
                ->orWhere('publisher', 'like', $term)
                ->orWhere('procurement_name', 'like', $term));
        }

        $rows = $query->get()->map(fn (ProductIdentity $p) => $this->present($p));

        return response()->json(['total' => $rows->count(), 'rows' => $rows]);
    }

    private function present(ProductIdentity $p): array
    {
        return [
            'id'               => $p->id,
            'name'             => $p->name,
            'publisher'        => $p->publisher,
            'procurement_name' => $p->procurement_name,
            'aliases'          => $p->aliases->map(fn ($a) => [
                'platform'   => $a->platform,
                'match_type' => $a->match_type,
                'pattern'    => $a->pattern,
            ])->values(),
            'licenses'         => $p->licenses->map(fn ($l) => [
                'id'          => $l->id,
                'name'        => $l->name,
                'fiscal_year' => $l->getRelation('pivot')->fiscal_year,
            ])->values(),
        ];
    }

    public function resolve(Request $request, ProductResolver $resolver): JsonResponse
    {
        $this->authorize('view', ProductIdentity::class);

        $data = $request->validate([
            'fiscal_year'             => 'nullable|string|max:16',
            'min_usage'               => 'nullable|numeric|min:0',
            'applications'            => 'required|array|max:5000',
            'applications.*.name'     => 'required|string|max:255',
            'applications.*.platform' => 'nullable|string|max:32',
            'applications.*.usage'    => 'nullable|numeric|min:0',
            'applications.*.devices'  => 'nullable|integer|min:0',
        ]);

        return response()->json($resolver->partition(
            $data['applications'],
            $data['fiscal_year'] ?? null,
            (float) ($data['min_usage'] ?? 0),
        ));
    }
}

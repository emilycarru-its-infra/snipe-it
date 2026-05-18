<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FilterRequest;
use App\Http\Transformers\LeaseDecisionsTransformer;
use App\Models\LeaseDecision;
use App\Models\Order;

class LeaseDecisionsController extends Controller
{
    /**
     * Display a listing of lease decisions.
     */
    public function index(FilterRequest $request): array
    {
        $this->authorize('view', Order::class);

        $allowed_columns = [
            'id',
            'contract_reference',
            'decision_type',
            'decision_date',
            'amount',
            'status',
            'created_at',
        ];

        $decisions = LeaseDecision::with('adminuser');

        if ($request->filled('filter') || $request->filled('search')) {
            $decisions->TextSearch($request->input('filter') ? $request->input('filter') : $request->input('search'));
        }

        if ($request->filled('status')) {
            $decisions->where('status', '=', $request->input('status'));
        }

        if ($request->filled('decision_type')) {
            $decisions->where('decision_type', '=', $request->input('decision_type'));
        }

        $offset = ($request->input('offset') > $decisions->count()) ? $decisions->count() : app('api_offset_value');
        $limit = app('api_limit_value');

        $order = $request->input('order') === 'asc' ? 'asc' : 'desc';
        $sort = in_array($request->input('sort'), $allowed_columns) ? $request->input('sort') : 'created_at';

        $decisions->orderBy($sort, $order);

        $total = $decisions->count();
        $decisions = $decisions->skip($offset)->take($limit)->get();

        return (new LeaseDecisionsTransformer)->transformLeaseDecisions($decisions, $total);
    }
}

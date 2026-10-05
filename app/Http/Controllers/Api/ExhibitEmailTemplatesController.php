<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Models\ExhibitEmailTemplate;
use App\Models\Order;
use App\Services\Exhibits\ExhibitEmailTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The exhibit student-email templates over the API, under the same Order
 * policy and model validation as the in-app editor. A PATCH changes only
 * the fields it sends. Templates are seeded, not created, so there is no
 * create or delete here either.
 */
class ExhibitEmailTemplatesController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('view', Order::class);

        $templates = ExhibitEmailTemplate::orderBy('name')->get();

        return response()->json(Helper::formatStandardApiResponse('success', [
            'total' => $templates->count(),
            'rows' => $templates,
        ], null));
    }

    public function show(ExhibitEmailTemplate $template): JsonResponse
    {
        $this->authorize('view', Order::class);

        return response()->json(Helper::formatStandardApiResponse('success', $template, null));
    }

    public function update(Request $request, ExhibitEmailTemplate $template): JsonResponse
    {
        $this->authorize('update', Order::class);

        $template->fill(ExhibitEmailTemplates::attributes($request->all(), partial: true));

        if (! $template->save()) {
            return response()->json(Helper::formatStandardApiResponse('error', null, $template->getErrors()));
        }

        return response()->json(Helper::formatStandardApiResponse('success', $template, trans('admin/exhibit-projects/general.template_updated')));
    }
}

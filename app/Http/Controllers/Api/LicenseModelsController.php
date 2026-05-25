<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Http\Transformers\LicenseModelsTransformer;
use App\Models\LicenseModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD for the LicenseModel catalog. Each row defines how the License
 * form and view should behave for licenses pointing at it (whether to
 * show Product Key, Seats, Checkout panel, etc.).
 *
 * Admins manage these via the UI in PR 3 of the redesign; this
 * controller backs both the API and the eventual admin UI.
 */
class LicenseModelsController extends Controller
{
    public function index(Request $request): JsonResponse|array
    {
        $this->authorize('view', LicenseModel::class);

        $allowed_columns = [
            'id', 'name', 'type_code', 'created_at', 'updated_at',
            'has_seats', 'has_product_key', 'has_checkout', 'is_subscription',
        ];

        $sort = in_array($request->input('sort'), $allowed_columns) ? $request->input('sort') : 'name';
        $order = $request->input('order') === 'desc' ? 'desc' : 'asc';
        $offset = (int) ($request->input('offset', 0));
        $limit = (int) ($request->input('limit', 50));

        $query = LicenseModel::query();

        if ($request->filled('search')) {
            $query->TextSearch($request->input('search'));
        }
        if ($request->filled('type_code')) {
            $query->where('type_code', '=', $request->input('type_code'));
        }

        $total = $query->count();
        $rows = $query->orderBy($sort, $order)->skip($offset)->take($limit)->get();

        return (new LicenseModelsTransformer)->transformLicenseModels($rows, $total);
    }

    public function show($id): JsonResponse|array
    {
        $this->authorize('view', LicenseModel::class);
        $model = LicenseModel::findOrFail($id);
        return (new LicenseModelsTransformer)->transformLicenseModel($model);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', LicenseModel::class);

        $model = new LicenseModel;
        $model->fill($request->all());

        if ($model->save()) {
            return response()->json(Helper::formatStandardApiResponse(
                'success',
                (new LicenseModelsTransformer)->transformLicenseModel($model),
                trans('admin/licensemodels/message.create.success')
            ));
        }
        return response()->json(Helper::formatStandardApiResponse('error', null, $model->getErrors()));
    }

    public function update(Request $request, $id): JsonResponse
    {
        $this->authorize('update', LicenseModel::class);

        $model = LicenseModel::findOrFail($id);
        $model->fill($request->all());

        if ($model->save()) {
            return response()->json(Helper::formatStandardApiResponse(
                'success',
                (new LicenseModelsTransformer)->transformLicenseModel($model),
                trans('admin/licensemodels/message.update.success')
            ));
        }
        return response()->json(Helper::formatStandardApiResponse('error', null, $model->getErrors()));
    }

    public function destroy($id): JsonResponse
    {
        $this->authorize('delete', LicenseModel::class);

        $model = LicenseModel::findOrFail($id);
        if ($model->licenses()->count() > 0) {
            return response()->json(Helper::formatStandardApiResponse(
                'error',
                null,
                trans('admin/licensemodels/message.assoc_licenses', ['count' => $model->licenses()->count()])
            ));
        }

        $model->delete();
        return response()->json(Helper::formatStandardApiResponse(
            'success',
            null,
            trans('admin/licensemodels/message.delete.success')
        ));
    }
}

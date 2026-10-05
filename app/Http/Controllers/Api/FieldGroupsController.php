<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Models\CustomField;
use App\Models\FieldGroup;
use App\Services\FieldGroupWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The field-group taxonomy over the API: the Field Groups admin page's
 * list, create, edit, delete and assign, under the same CustomField policy.
 * A PATCH changes only the fields it sends.
 */
class FieldGroupsController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('view', CustomField::class);

        $groups = FieldGroup::ordered()->withCount('fields')->get();

        return response()->json(Helper::formatStandardApiResponse('success', [
            'total' => $groups->count(),
            'rows' => $groups,
        ], null));
    }

    public function show(FieldGroup $group): JsonResponse
    {
        $this->authorize('view', CustomField::class);

        return response()->json(Helper::formatStandardApiResponse('success', $group->loadCount('fields'), null));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('update', CustomField::class);

        $group = new FieldGroup;
        $group->fill(FieldGroupWriter::attributes($request->all()));

        return $this->saved($group);
    }

    public function update(Request $request, FieldGroup $group): JsonResponse
    {
        $this->authorize('update', CustomField::class);

        $group->fill(FieldGroupWriter::attributes($request->all(), partial: true));

        return $this->saved($group);
    }

    public function destroy(FieldGroup $group): JsonResponse
    {
        $this->authorize('update', CustomField::class);

        FieldGroupWriter::remove($group);

        return response()->json(Helper::formatStandardApiResponse('success', null, trans('admin/custom_fields/general.field_group_deleted')));
    }

    /** Put a custom field in a group; a blank field_group_id takes it out. */
    public function assign(Request $request, CustomField $field): JsonResponse
    {
        $this->authorize('update', CustomField::class);

        try {
            FieldGroupWriter::assign($field, $request->input('field_group_id'));
        } catch (ValidationException $e) {
            return response()->json(Helper::formatStandardApiResponse('error', null, $e->errors()));
        }

        return response()->json(Helper::formatStandardApiResponse('success', [
            'field_id' => $field->id,
            'field_group_id' => $field->field_group_id,
        ], trans('admin/custom_fields/general.field_group_assigned')));
    }

    private function saved(FieldGroup $group): JsonResponse
    {
        if (! $group->save()) {
            return response()->json(Helper::formatStandardApiResponse('error', null, $group->getErrors()));
        }

        return response()->json(Helper::formatStandardApiResponse('success', $group, trans('admin/custom_fields/general.field_group_saved')));
    }
}

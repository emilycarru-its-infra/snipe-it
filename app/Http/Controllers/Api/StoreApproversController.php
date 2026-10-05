<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Models\StoreApprover;
use App\Services\StoreApprovers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The store-approver list over the API: read it, add one person, remove
 * one person. Superuser only, as the procurement page's approvers dialog
 * is, with the same check on the user id.
 */
class StoreApproversController extends Controller
{
    public function index(): JsonResponse
    {
        abort_unless(StoreApprovers::canManage(auth()->user()), 403);

        $approvers = StoreApprover::with('user')->orderBy('id')->get()->map(fn (StoreApprover $approver) => [
            'user_id' => $approver->user_id,
            'name' => $approver->user?->getFullNameAttribute(),
            'email' => $approver->user?->email,
            'created_by' => $approver->created_by,
            'created_at' => $approver->created_at?->toDateTimeString(),
        ]);

        return response()->json(Helper::formatStandardApiResponse('success', [
            'total' => $approvers->count(),
            'rows' => $approvers,
        ], null));
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(StoreApprovers::canManage(auth()->user()), 403);

        $validator = validator($request->all(), ['user_id' => 'required|'.StoreApprovers::USER_RULE]);

        if ($validator->fails()) {
            return response()->json(Helper::formatStandardApiResponse('error', null, $validator->errors()));
        }

        $approver = StoreApprovers::add((int) $request->input('user_id'), auth()->id());

        return response()->json(Helper::formatStandardApiResponse('success', [
            'user_id' => $approver->user_id,
        ], trans('admin/store/general.approver_added')));
    }

    public function destroy(int $user): JsonResponse
    {
        abort_unless(StoreApprovers::canManage(auth()->user()), 403);

        if (! StoreApprovers::remove($user)) {
            return response()->json(Helper::formatStandardApiResponse('error', null, trans('admin/store/general.approver_not_listed')));
        }

        return response()->json(Helper::formatStandardApiResponse('success', null, trans('admin/store/general.approver_removed')));
    }
}

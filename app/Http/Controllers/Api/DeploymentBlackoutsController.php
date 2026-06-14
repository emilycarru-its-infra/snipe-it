<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\StaffBlackout;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Token-authenticated upsert endpoint for staff availability blackouts,
 * called by the Python deployment-staff-sync function as it mirrors M365 /
 * Entra calendar OOO events into Snipe. Each Graph event carries a stable
 * external_id, so we upsert on that key (idempotent re-sync). source defaults
 * to 'graph'. Writes are gated by the Order policy via authorize(). Responses
 * use Snipe's standard {status, messages, payload} envelope.
 */
class DeploymentBlackoutsController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $this->authorize('update', Order::class);

        $validator = validator($request->all(), [
            'user_id' => 'required_without:email|nullable|integer',
            'email' => 'required_without:user_id|nullable|email',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
            'reason' => 'nullable|string|max:191',
            'external_id' => 'nullable|string|max:191',
            'source' => 'nullable|string|max:16',
        ]);

        if ($validator->fails()) {
            return response()->json(
                Helper::formatStandardApiResponse('error', null, $validator->errors()),
                422,
            );
        }

        // Resolve the staff member by id or email.
        $user = $this->resolveUser($request);
        if (! $user) {
            return response()->json(
                Helper::formatStandardApiResponse('error', null, trans('admin/deployments/general.blackout_user_unknown')),
                404,
            );
        }

        $attributes = [
            'user_id' => $user->id,
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
            'reason' => $request->input('reason'),
            'source' => $request->input('source') ?: 'graph',
            'synced_at' => now(),
        ];

        $externalId = $request->input('external_id');

        // Upsert by external_id when the caller supplies one (the Graph event
        // id); otherwise create a fresh row each call.
        if ($externalId) {
            $blackout = StaffBlackout::firstOrNew(['external_id' => $externalId]);
            $blackout->fill($attributes);
            $blackout->external_id = $externalId;
        } else {
            $blackout = new StaffBlackout($attributes);
        }

        if (! $blackout->save()) {
            return response()->json(
                Helper::formatStandardApiResponse('error', null, $blackout->getErrors()),
                422,
            );
        }

        return response()->json(
            Helper::formatStandardApiResponse(
                'success',
                $blackout->load('user'),
                trans('admin/deployments/general.blackout_saved'),
            ),
        );
    }

    /** Resolve the target user by user_id, falling back to email (exact match). */
    private function resolveUser(Request $request): ?User
    {
        if ($request->filled('user_id')) {
            return User::find((int) $request->input('user_id'));
        }

        if ($request->filled('email')) {
            return User::where('email', $request->input('email'))->first();
        }

        return null;
    }
}

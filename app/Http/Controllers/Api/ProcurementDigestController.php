<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Requisition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * Post the "waiting on procurement" digest now, rather than at its weekday
 * time. The container has no shell to run artisan in, so without this the
 * only way to see the digest after changing it, or after fixing its channel,
 * is to wait for the next morning.
 */
class ProcurementDigestController extends Controller
{
    public function run(Request $request): JsonResponse
    {
        $this->authorize('update', Requisition::class);

        $request->validate(['dry_run' => 'sometimes|boolean']);

        $exit = Artisan::call('snipeit:procurement-actions', [
            '--dry-run' => $request->boolean('dry_run'),
        ]);

        return response()->json([
            'status' => $exit === 0 ? 'success' : 'error',
            'dry_run' => $request->boolean('dry_run'),
            'output' => trim(Artisan::output()),
        ], $exit === 0 ? 200 : 500);
    }
}

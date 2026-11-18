<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Models\EmailLog;
use App\Services\EmailLogQuery;
use App\Services\EmailLogRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailLogsController extends Controller
{
    /** The email log, newest first, filtered by record, recipient or subject. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('email-log.view');

        $validated = $request->validate([
            'type' => 'nullable|string|in:'.implode(',', array_keys(EmailLogQuery::TYPES)),
            'id' => 'nullable|integer|required_with:type',
            'search' => 'nullable|string|max:191',
            'limit' => 'nullable|integer|min:1|max:500',
            'offset' => 'nullable|integer|min:0',
        ]);

        $query = EmailLogQuery::build($validated['type'] ?? null, $validated['id'] ?? null, $validated['search'] ?? null);
        $total = (clone $query)->count();
        $rows = $query->with('links')
            ->offset($validated['offset'] ?? 0)
            ->limit($validated['limit'] ?? 50)
            ->get();

        return response()->json([
            'total' => $total,
            'rows' => $rows->map(fn (EmailLog $log) => EmailLogQuery::present($log))->all(),
        ]);
    }

    /**
     * Import messages from the mail system's delivery trace, for mail sent
     * before the log existed. Superusers only: the rows become the audit
     * record.
     */
    public function import(Request $request, EmailLogRecorder $recorder): JsonResponse
    {
        if (! $request->user()?->isSuperUser()) {
            return response()->json(Helper::formatStandardApiResponse('error', null, trans('general.insufficient_permissions')), 403);
        }

        $validated = $request->validate([
            'source' => 'nullable|string|in:'.EmailLog::SOURCE_EXCHANGE,
            'entries' => 'required|array|min:1|max:1000',
            'entries.*.message_id' => 'nullable|string|max:500',
            'entries.*.sent_at' => 'required|date',
            'entries.*.from' => 'nullable|email',
            'entries.*.to' => 'required|array|min:1|max:100',
            'entries.*.to.*' => 'email',
            'entries.*.subject' => 'nullable|string|max:1000',
            'entries.*.status' => 'nullable|string|max:64',
        ]);

        $counts = $recorder->import($validated['entries'], $validated['source'] ?? EmailLog::SOURCE_EXCHANGE);

        return response()->json(Helper::formatStandardApiResponse('success', $counts, trans('admin/email-log/general.imported', $counts)));
    }
}

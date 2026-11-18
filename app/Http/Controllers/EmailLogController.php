<?php

namespace App\Http\Controllers;

use App\Services\EmailLogQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** The email log report: every email Snipe sent, searchable and filterable by record. */
class EmailLogController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('email-log.view');

        $type = $request->string('type')->toString() ?: null;
        $id = $request->integer('id') ?: null;
        $search = $request->string('search')->toString() ?: null;

        if ($type !== null && ! isset(EmailLogQuery::TYPES[$type])) {
            $type = null;
        }

        $emails = EmailLogQuery::build($type, $id, $search)
            ->paginate(50)
            ->withQueryString();

        return view('reports.emails', [
            'emails' => $emails,
            'type' => $type,
            'id' => $id,
            'search' => $search,
            'types' => array_keys(EmailLogQuery::TYPES),
        ]);
    }
}

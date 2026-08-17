<?php

namespace App\Http\Controllers;

use App\Services\PageAccessAudit;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The live answer to "who can see this page?" for the pages that carry
 * money, contracts, leases and people's transactions.
 *
 * Superuser-only by the route's own middleware: the roster names every
 * person who can reach a sensitive page, which is exactly the map you would
 * not hand to someone who has no business administering access.
 */
class AccessAuditController extends Controller
{
    public function show(Request $request, PageAccessAudit $audit): View
    {
        $path = (string) $request->input('path', '');

        // Only the declared sensitive areas are auditable. Not a security
        // boundary — the route is superuser-only — but it keeps the page
        // honest: it answers for pages it actually knows how to answer for,
        // rather than rendering an empty roster for an arbitrary URL.
        if (! PageAccessAudit::isAuditable($path)) {
            return view('access-audit.unknown', ['path' => $path]);
        }

        return view('access-audit.show', $audit->audit($path));
    }
}

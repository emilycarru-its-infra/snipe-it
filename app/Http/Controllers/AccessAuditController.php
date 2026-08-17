<?php

namespace App\Http\Controllers;

use App\Services\PageAccessAudit;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The live answer to "who can see this page?" for the pages that carry
 * money, contracts, leases and people's transactions.
 *
 * Open to exactly the audience of the page being audited: the roster is
 * gated by re-running that page's own abilities for the requester. Anyone
 * who can open the page can see who else can — transparency about access,
 * scoped to people who already hold the data the access is to.
 */
class AccessAuditController extends Controller
{
    public function show(Request $request, PageAccessAudit $audit): View
    {
        $path = (string) $request->input('path', '');

        // Paths outside the declared sensitive areas have no gate to borrow,
        // so the honest "this page is not audited" answer stays with the
        // people who administer access.
        if (! PageAccessAudit::isAuditable($path)) {
            abort_unless($request->user()->isSuperUser(), 403);

            return view('access-audit.unknown', ['path' => $path]);
        }

        abort_unless(PageAccessAudit::viewerCan($request->user(), $path), 403);

        return view('access-audit.show', $audit->audit($path));
    }
}

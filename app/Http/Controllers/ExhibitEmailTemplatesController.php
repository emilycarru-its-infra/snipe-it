<?php

namespace App\Http\Controllers;

use App\Models\ExhibitEmailTemplate;
use App\Models\Order;
use App\Services\Exhibits\ExhibitEmailTemplates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Tiny editor for the DB-backed exhibit student-email templates. Admins
 * edit the subject + body (with {{merge_variables}}) in-app — no
 * redeploy — the way the year's pickup dates/links are refreshed each
 * cycle. Authorization reuses the Order policy.
 */
class ExhibitEmailTemplatesController extends Controller
{
    public function index()
    {
        $this->authorize('view', Order::class);

        return view('exhibit-email-templates.index', [
            'templates' => ExhibitEmailTemplate::orderBy('name')->get(),
        ]);
    }

    public function edit(ExhibitEmailTemplate $exhibitEmailTemplate)
    {
        $this->authorize('update', Order::class);

        return view('exhibit-email-templates.edit', ['template' => $exhibitEmailTemplate]);
    }

    public function update(Request $request, ExhibitEmailTemplate $exhibitEmailTemplate): RedirectResponse
    {
        $this->authorize('update', Order::class);

        $exhibitEmailTemplate->fill(ExhibitEmailTemplates::attributes($request->all()));

        if (! $exhibitEmailTemplate->save()) {
            return redirect()->back()->withInput()->withErrors($exhibitEmailTemplate->getErrors());
        }

        return redirect()->route('exhibit-email-templates.index')
            ->with('success', trans('admin/exhibit-projects/general.template_updated'));
    }
}

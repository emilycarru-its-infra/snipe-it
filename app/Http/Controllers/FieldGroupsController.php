<?php

namespace App\Http\Controllers;

use App\Models\CustomField;
use App\Models\FieldGroup;
use App\Services\FieldGroupWriter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * CRUD for the editable field-group taxonomy (Specs, Lease & Procurement,
 * Identity, Metadata, …) plus a quick action to assign a custom field to a
 * group. Groups drive the per-box rendering on the asset detail view.
 *
 * Authorization reuses the CustomField policy — the same gate that protects
 * the custom-fields admin screen these groups live alongside.
 */
class FieldGroupsController extends Controller
{
    public function index(): View
    {
        $this->authorize('view', CustomField::class);

        return view('field-groups.index', [
            'groups' => FieldGroup::ordered()->withCount('fields')->get(),
            'fields' => CustomField::with('group')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('update', CustomField::class);

        return view('field-groups.form', [
            'item' => new FieldGroup(['active' => true, 'color' => '#3498db', 'sort_order' => 0, 'collapsed_by_default' => false]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('update', CustomField::class);

        $item = new FieldGroup;
        $item->fill(FieldGroupWriter::attributes($request->all()));

        if (! $item->save()) {
            return redirect()->back()->withInput()->withErrors($item->getErrors());
        }

        return redirect()->route('field-groups.index')
            ->with('success', trans('admin/custom_fields/general.field_group_saved'));
    }

    public function edit(FieldGroup $fieldGroup): View
    {
        $this->authorize('update', CustomField::class);

        return view('field-groups.form', ['item' => $fieldGroup]);
    }

    public function update(Request $request, FieldGroup $fieldGroup): RedirectResponse
    {
        $this->authorize('update', CustomField::class);

        $fieldGroup->fill(FieldGroupWriter::attributes($request->all()));

        if (! $fieldGroup->save()) {
            return redirect()->back()->withInput()->withErrors($fieldGroup->getErrors());
        }

        return redirect()->route('field-groups.index')
            ->with('success', trans('admin/custom_fields/general.field_group_saved'));
    }

    public function destroy(FieldGroup $fieldGroup): RedirectResponse
    {
        $this->authorize('update', CustomField::class);

        FieldGroupWriter::remove($fieldGroup);

        return redirect()->route('field-groups.index')
            ->with('success', trans('admin/custom_fields/general.field_group_deleted'));
    }

    /**
     * Inline assign a single custom field to a group (or clear it) from the
     * field-groups admin list, without opening the full custom-field editor.
     */
    public function assign(Request $request, CustomField $field): RedirectResponse
    {
        $this->authorize('update', CustomField::class);

        FieldGroupWriter::assign($field, $request->input('field_group_id'));

        return redirect()->route('field-groups.index')
            ->with('success', trans('admin/custom_fields/general.field_group_assigned'));
    }
}

<?php

namespace App\Http\Controllers;

use App\Forms\FormRegistry;
use App\Services\FormAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Generic dispatcher for the /forms platform. Each route resolves the
 * form module by slug, applies access gates, and delegates rendering /
 * submission handling to the module. The controller itself owns no
 * domain logic — adding a new form means writing a new FormDefinition
 * subclass and registering it in config/forms.php.
 */
class FormsController extends Controller
{
    public function index(): View
    {
        $user = Auth::user();
        return view('forms.index', [
            'forms'   => FormRegistry::accessibleTo($user),
            'isAdmin' => FormAccess::isAdmin($user),
        ]);
    }

    public function show(string $slug): View
    {
        $form = $this->resolveOrFail($slug);
        $user = Auth::user();
        abort_unless(FormAccess::canAccess($user, $slug), 403, trans('admin/forms/general.not_eligible'));
        return $form->show($user);
    }

    public function submit(Request $request, string $slug): RedirectResponse
    {
        $form = $this->resolveOrFail($slug);
        $user = Auth::user();
        abort_unless(FormAccess::canSubmit($user, $slug), 403, trans('admin/forms/general.not_eligible'));
        return $form->submit($request, $user);
    }

    public function success(string $slug): View
    {
        $form = $this->resolveOrFail($slug);
        $user = Auth::user();
        abort_unless(FormAccess::canAccess($user, $slug), 403, trans('admin/forms/general.not_eligible'));
        return $form->success($user);
    }

    public function submissionsIndex(string $slug): View
    {
        $form = $this->resolveOrFail($slug);
        abort_unless(FormAccess::isAdmin(Auth::user()), 403, trans('admin/forms/general.admin_only'));
        return $form->submissionsIndexView($form->submissionsIndexQuery());
    }

    public function submissionShow(string $slug, int|string $id): View
    {
        $form = $this->resolveOrFail($slug);
        $submission = $form->findSubmission($id);
        abort_unless($submission, 404);
        abort_unless(
            FormAccess::canViewSubmission(Auth::user(), $form->submissionOwnerId($submission)),
            403,
            trans('admin/forms/general.not_eligible'),
        );
        return $form->submissionShow($submission);
    }

    private function resolveOrFail(string $slug)
    {
        $form = FormRegistry::find($slug);
        abort_unless($form, 404);
        return $form;
    }
}

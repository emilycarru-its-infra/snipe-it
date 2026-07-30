<?php

namespace App\Http\Controllers\Api;

use App\Forms\FormRegistry;
use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Models\FormEligibility;
use App\Models\Group;
use App\Services\FormAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Which permission groups may submit which form.
 *
 * The binding lives in one table and was previously reachable only through
 * Admin → Settings → Forms, which is SSO-gated. That makes it invisible to
 * anything headless — including a check as basic as "can faculty actually
 * open the faculty program form", whose answer is the difference between a
 * working intake and a 403 on the first step. Same reasoning as the catalog
 * identifiers: configuration that decides whether a flow works at all needs
 * a way in that is not a browser session.
 *
 * Superuser-gated, matching the settings page it mirrors.
 */
class FormEligibilityController extends Controller
{
    /**
     * Every registered form with the groups bound to it, and the full group
     * list, so a caller can resolve names to ids without a second endpoint.
     */
    public function index(): JsonResponse
    {
        abort_unless(auth()->user()?->isSuperUser(), 403);

        $bound = FormEligibility::all()->groupBy('form_slug')
            ->map(fn ($rows) => $rows->pluck('group_id')->map(fn ($id) => (int) $id)->values()->all());

        $groups = Group::query()->orderBy('name')->get(['id', 'name'])
            ->map(fn (Group $group) => ['id' => (int) $group->id, 'name' => $group->name]);

        $forms = collect(array_keys(FormRegistry::modules()))->map(fn (string $slug) => [
            'slug' => $slug,
            'group_ids' => $bound->get($slug, []),
            'group_names' => $groups->whereIn('id', $bound->get($slug, []))->pluck('name')->values()->all(),
        ]);

        return response()->json(Helper::formatStandardApiResponse('success', [
            'forms' => $forms->values()->all(),
            'groups' => $groups->values()->all(),
        ], null));
    }

    /**
     * Replace one form's group list. The whole list, not a delta, so the
     * result of a call does not depend on what was there before — the same
     * contract the settings page has, and the one that makes a repeated call
     * safe.
     */
    public function update(Request $request, string $slug): JsonResponse
    {
        abort_unless(auth()->user()?->isSuperUser(), 403);

        if (! array_key_exists($slug, FormRegistry::modules())) {
            return response()->json(
                Helper::formatStandardApiResponse('error', null, 'No such form: '.$slug),
                404
            );
        }

        $validated = $request->validate([
            'group_ids' => 'present|array',
            'group_ids.*' => 'integer|exists:permission_groups,id',
        ]);

        $desired = collect($validated['group_ids'])->map(fn ($id) => (int) $id)->unique();
        $existing = FormEligibility::where('form_slug', $slug)->pluck('group_id')
            ->map(fn ($id) => (int) $id);

        foreach ($desired->diff($existing) as $groupId) {
            FormEligibility::create(['form_slug' => $slug, 'group_id' => $groupId]);
        }

        $stale = $existing->diff($desired);
        if ($stale->isNotEmpty()) {
            FormEligibility::where('form_slug', $slug)->whereIn('group_id', $stale->all())->delete();
        }

        // The service memoises eligibility per request; a write has to clear
        // it or a read in the same request would answer from before the write.
        FormAccess::flush();

        return response()->json(Helper::formatStandardApiResponse('success', [
            'slug' => $slug,
            'group_ids' => FormEligibility::where('form_slug', $slug)->pluck('group_id')
                ->map(fn ($id) => (int) $id)->values()->all(),
        ], trans('admin/forms/general.settings_saved')));
    }
}

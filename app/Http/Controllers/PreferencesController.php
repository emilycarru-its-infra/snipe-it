<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Models\Statuslabel;
use App\Services\Settings\Preferences;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Admin → Settings → Preferences, and the same registry over the API. Both
 * write through Preferences::update(), so the page and a PATCH validate and
 * log identically.
 */
class PreferencesController extends Controller
{
    public function index(): View
    {
        return view('settings.preferences', [
            'groups' => collect(Preferences::all())->groupBy('group'),
            'statusLabels' => Statuslabel::orderBy('name')->pluck('name')->all(),
        ]);
    }

    /**
     * Save the page. Only fields that differ from what is in effect are
     * written, and a ticked "reset" drops that key's override.
     */
    public function save(Request $request): RedirectResponse
    {
        $input = (array) $request->input('prefs', []);
        $resets = array_keys(array_filter((array) $request->input('reset', [])));

        try {
            $changes = Preferences::changesFrom(array_diff_key($input, array_flip($resets)));
            if ($changes) {
                Preferences::update($changes, auth()->id());
            }
        } catch (ValidationException $e) {
            return redirect()->route('settings.preferences.index')->withInput()->withErrors($e->errors());
        }

        foreach ($resets as $key) {
            if (Preferences::definition((string) $key)) {
                Preferences::reset((string) $key, auth()->id());
            }
        }

        return redirect()->route('settings.preferences.index')
            ->with('success', trans('admin/settings/message.update.success'));
    }

    /** Every preference with its group, type, default, current value and whether it is overridden. */
    public function apiIndex(): JsonResponse
    {
        return response()->json(['preferences' => Preferences::all()]);
    }

    /** Change some preferences: `{key: value}`. Unknown keys or invalid values refuse the whole change. */
    public function apiUpdate(Request $request): JsonResponse
    {
        try {
            $changed = Preferences::update($request->all(), auth()->id());
        } catch (ValidationException $e) {
            return response()->json(Helper::formatStandardApiResponse('error', null, $e->errors()));
        }

        return response()->json(Helper::formatStandardApiResponse(
            'success',
            ['changed' => array_keys($changed), 'preferences' => Preferences::all()],
            trans('admin/settings/message.update.success'),
        ));
    }

    /** Reset one key to its default. */
    public function apiReset(string $key): JsonResponse
    {
        if (! Preferences::definition($key)) {
            return response()->json(Helper::formatStandardApiResponse('error', null, trans('admin/settings/preferences.unknown_keys', ['keys' => $key])), 404);
        }

        Preferences::reset($key, auth()->id());

        return response()->json(Helper::formatStandardApiResponse(
            'success',
            Preferences::all()[$key],
            trans('admin/settings/preferences.reset_success', ['key' => $key]),
        ));
    }
}

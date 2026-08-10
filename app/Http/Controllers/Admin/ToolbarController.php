<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\ToolbarConfig;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * The toolbar builder: drag the top-level tabs into the order you want,
 * hide the ones you don't. The same config is readable and writable over
 * /api/v1/settings/toolbar so the toolbar can be reshaped agentically.
 * Superuser-gated by the /admin route group; the API endpoints gate on
 * the admin ability themselves.
 */
class ToolbarController extends Controller
{
    public function edit()
    {
        return view('admin.toolbar', [
            'rows' => ToolbarConfig::editorRows(),
        ]);
    }

    public function update(Request $request)
    {
        $request->validate(['tabs' => 'required|array|min:1']);

        ToolbarConfig::save($request->input('tabs'));

        return redirect()->route('admin.toolbar.edit')
            ->with('success', trans('admin/settings/general.toolbar_saved'));
    }

    public function apiShow()
    {
        $this->authorize('admin');

        return response()->json([
            'status' => 'success',
            'payload' => [
                'tabs' => ToolbarConfig::editorRows(),
                'registry' => array_keys(ToolbarConfig::TABS),
            ],
        ]);
    }

    public function apiUpdate(Request $request)
    {
        $this->authorize('admin');

        $request->validate(['tabs' => 'required|array|min:1']);

        $config = ToolbarConfig::save($request->input('tabs'));

        return response()->json(['status' => 'success', 'payload' => $config]);
    }
}

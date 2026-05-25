<?php

namespace App\Http\Controllers;

use App\Models\LicenseModel;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Web-side CRUD for LicenseModel — admins configure what types of
 * licenses (SaaS, product-key, license-server, etc.) the system
 * recognizes, and which form fields each type shows.
 *
 * API-side CRUD lives in Api\LicenseModelsController (shipped in PR #81).
 * Lives under /admin so it inherits the superuser middleware on that
 * route group.
 */
class LicenseModelsController extends Controller
{
    public function index(): View
    {
        $this->authorize('view', LicenseModel::class);
        return view('license-models/index');
    }

    public function create(): View
    {
        $this->authorize('create', LicenseModel::class);
        return view('license-models/edit', ['item' => new LicenseModel]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', LicenseModel::class);

        $model = new LicenseModel;
        $model->fill($this->normalizeInput($request));

        if ($model->save()) {
            return redirect()->route('license-models.index')
                ->with('success', trans('admin/licensemodels/message.create.success'));
        }
        return redirect()->back()->withInput()->withErrors($model->getErrors());
    }

    public function edit(LicenseModel $licenseModel): View
    {
        $this->authorize('update', LicenseModel::class);
        return view('license-models/edit', ['item' => $licenseModel]);
    }

    public function update(Request $request, LicenseModel $licenseModel): RedirectResponse
    {
        $this->authorize('update', LicenseModel::class);

        $licenseModel->fill($this->normalizeInput($request));

        if ($licenseModel->save()) {
            return redirect()->route('license-models.index')
                ->with('success', trans('admin/licensemodels/message.update.success'));
        }
        return redirect()->back()->withInput()->withErrors($licenseModel->getErrors());
    }

    public function show(LicenseModel $licenseModel): View
    {
        $this->authorize('view', LicenseModel::class);
        return view('license-models/view', ['item' => $licenseModel]);
    }

    public function destroy(LicenseModel $licenseModel): RedirectResponse
    {
        $this->authorize('delete', LicenseModel::class);

        if ($licenseModel->licenses()->count() > 0) {
            return redirect()->route('license-models.index')
                ->with('error', trans('admin/licensemodels/message.assoc_licenses', [
                    'count' => $licenseModel->licenses()->count(),
                ]));
        }

        $licenseModel->delete();
        return redirect()->route('license-models.index')
            ->with('success', trans('admin/licensemodels/message.delete.success'));
    }

    /**
     * Convert checkbox absence into explicit `0` and coerce blank
     * `default_seats` into 0 so validation against the integer/min:0
     * rule passes when admins leave fields empty.
     */
    private function normalizeInput(Request $request): array
    {
        $flags = ['has_seats','has_product_key','has_checkout','has_expiration',
                  'has_user_email','has_reassignable','is_subscription','default_reassignable'];
        $data = $request->only(array_merge(
            ['name','type_code','description','icon','default_seats','fieldset_id'],
            $flags,
        ));
        foreach ($flags as $flag) {
            $data[$flag] = $request->has($flag) ? 1 : 0;
        }
        $data['default_seats'] = (int) ($data['default_seats'] ?? 0);
        if (empty($data['fieldset_id'])) {
            $data['fieldset_id'] = null;
        }
        return $data;
    }
}

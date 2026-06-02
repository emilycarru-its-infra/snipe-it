<?php

namespace App\Http\Controllers\Consumables;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Http\Requests\ImageUploadRequest;
use App\Http\Requests\StoreConsumableRequest;
use App\Models\Company;
use App\Models\Consumable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * This controller handles all actions related to Consumables for
 * the Snipe-IT Asset Management application.
 *
 * @version    v1.0
 */
class ConsumablesController extends Controller
{
    /**
     * Return a view to display component information.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @see ConsumablesController::getDatatable() method that generates the JSON response
     * @since [v1.0]
     *
     * @return View
     *
     * @throws AuthorizationException
     */
    public function index()
    {
        $this->authorize('index', Consumable::class);

        return view('consumables/index');
    }

    /**
     * Return a view to display the form view to create a new consumable
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @see ConsumablesController::postCreate() method that stores the form data
     * @since [v1.0]
     *
     * @return View
     *
     * @throws AuthorizationException
     */
    public function create()
    {
        $this->authorize('create', Consumable::class);

        return view('consumables.edit')->with('category_type', 'consumable')
            ->with('item', new Consumable);
    }

    /**
     * Validate and store new consumable data.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @see ConsumablesController::getCreate() method that returns the form view
     * @since [v1.0]
     *
     * @param  ImageUploadRequest  $request
     * @return RedirectResponse
     *
     * @throws AuthorizationException
     */
    public function store(StoreConsumableRequest $request)
    {
        $this->authorize('create', Consumable::class);
        $consumable = new Consumable;
        $consumable->name = $request->input('name');
        $consumable->category_id = $request->input('category_id');
        $consumable->supplier_id = $request->input('supplier_id');
        $consumable->location_id = $request->input('location_id');
        $consumable->company_id = Company::getIdForCurrentUser($request->input('company_id'));
        $consumable->order_number = $request->input('order_number');
        $consumable->tracking_number = $request->input('tracking_number');
        $consumable->tracking_carrier = $request->input('tracking_carrier');
        $consumable->min_amt = $request->input('min_amt');
        $consumable->manufacturer_id = $request->input('manufacturer_id');
        $consumable->model_number = $request->input('model_number');
        $consumable->item_no = $request->input('item_no');
        $consumable->purchase_date = $request->input('purchase_date');
        $consumable->purchase_cost = $request->input('purchase_cost');
        $consumable->qty = $request->input('qty');
        $consumable->created_by = auth()->id();
        $consumable->notes = $request->input('notes');
        $consumable->on_maintenance_contract = $request->filled('on_maintenance_contract');
        $consumable->status = $request->input('status', 'active');

        if ($request->has('use_cloned_image')) {
            $cloned_model_img = Consumable::select('image')->find($request->input('clone_image_from_id'));
            if ($cloned_model_img) {
                $new_image_name = 'clone-'.date('U').'-'.$cloned_model_img->image;
                $new_image = 'consumables/'.$new_image_name;
                Storage::disk('public')->copy('consumables/'.$cloned_model_img->image, $new_image);
                $consumable->image = $new_image_name;
            }

        } else {
            $consumable = $request->handleImages($consumable);
        }

        if ($request->input('redirect_option') === 'back') {
            session()->put(['redirect_option' => 'index']);
        } else {
            session()->put(['redirect_option' => $request->input('redirect_option')]);
        }

        if ($consumable->save()) {
            $consumable->compatibleModels()->sync(array_filter((array) $request->input('compatible_models', [])));

            return Helper::getRedirectOption($request, $consumable->id, 'Consumables')
                ->with('success', trans('admin/consumables/message.create.success'));
        }

        return redirect()->back()->withInput()->withErrors($consumable->getErrors());
    }

    /**
     * Returns a form view to edit a consumable.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @param  int  $consumableId
     *
     * @see ConsumablesController::postEdit() method that stores the form data.
     * @since [v1.0]
     */
    public function edit(Consumable $consumable): View|RedirectResponse
    {
        $this->authorize($consumable);
        session()->put('url.intended', url()->previous());

        return view('consumables/edit')
            ->with('item', $consumable)
            ->with('category_type', 'consumable');

    }

    /**
     * Returns a form view to edit a consumable.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @param  ImageUploadRequest  $request
     * @param  int  $consumableId
     * @return RedirectResponse
     *
     * @throws AuthorizationException
     *
     * @see ConsumablesController::getEdit() method that stores the form data.
     * @since [v1.0]
     */
    public function update(StoreConsumableRequest $request, Consumable $consumable)
    {

        $min = $consumable->numCheckedOut();
        $validator = Validator::make($request->all(), [
            'qty' => "required|numeric|min:$min",
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $this->authorize($consumable);

        $consumable->name = $request->input('name');
        $consumable->category_id = $request->input('category_id');
        $consumable->supplier_id = $request->input('supplier_id');
        $consumable->location_id = $request->input('location_id');
        $consumable->company_id = Company::getIdForCurrentUser($request->input('company_id'));
        $consumable->order_number = $request->input('order_number');
        $consumable->tracking_number = $request->input('tracking_number');
        $consumable->tracking_carrier = $request->input('tracking_carrier');
        $consumable->min_amt = $request->input('min_amt');
        $consumable->manufacturer_id = $request->input('manufacturer_id');
        $consumable->model_number = $request->input('model_number');
        $consumable->item_no = $request->input('item_no');
        $consumable->purchase_date = $request->input('purchase_date');
        $consumable->purchase_cost = $request->input('purchase_cost');
        $consumable->qty = Helper::ParseFloat($request->input('qty'));
        $consumable->notes = $request->input('notes');
        $consumable->on_maintenance_contract = $request->filled('on_maintenance_contract');
        $consumable->status = $request->input('status', $consumable->status ?? 'active');

        $consumable = $request->handleImages($consumable);

        session()->put(['redirect_option' => $request->input('redirect_option')]);

        if ($consumable->save()) {
            $consumable->compatibleModels()->sync(array_filter((array) $request->input('compatible_models', [])));

            return Helper::getRedirectOption($request, $consumable->id, 'Consumables')
                ->with('success', trans('admin/consumables/message.update.success'));
        }

        return redirect()->back()->withInput()->withErrors($consumable->getErrors());
    }

    /**
     * Delete a consumable.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @param  int  $consumableId
     *
     * @since [v1.0]
     *
     * @return RedirectResponse
     *
     * @throws AuthorizationException
     */
    public function destroy($consumableId)
    {
        if (is_null($consumable = Consumable::find($consumableId))) {
            return redirect()->route('consumables.index')->with('error', trans('admin/consumables/message.not_found'));
        }
        $this->authorize($consumable);

        $consumable->delete();

        // Redirect to the locations management page
        return redirect()->route('consumables.index')->with('success', trans('admin/consumables/message.delete.success'));
    }

    /**
     * Return a view to display component information.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @see ConsumablesController::getDataView() method that generates the JSON response
     * @since [v1.0]
     *
     * @param  int  $consumableId
     * @return View
     *
     * @throws AuthorizationException
     */
    public function show(Consumable $consumable)
    {
        $consumable = Consumable::withCount('users as users_consumables')->find($consumable->id);
        $this->authorize($consumable);

        return view('consumables/view', compact('consumable'));
    }

    /**
     * Nudge a consumable's quantity inline (from the toner dashboard or the
     * info-panel stepper) without round-tripping the full edit form.
     *
     * Accepts either a relative `delta` (e.g. +1 / -1) or an absolute `qty`.
     * The new value is clamped to never drop below what's already checked
     * out and never below zero. The change saves through the normal model
     * path, so ConsumableObserver records it in the activity log (who, when,
     * and the old→new quantity) exactly like a full edit — no separate table
     * needed; it shows on the consumable's History tab.
     *
     * @author [R. Christiansen]
     */
    public function adjustQuantity(Request $request, Consumable $consumable): JsonResponse
    {
        $this->authorize('update', $consumable);

        $request->validate([
            'delta' => 'sometimes|integer',
            'qty' => 'sometimes|integer|min:0|max:99999',
        ]);

        $checkedOut = (int) $consumable->numCheckedOut();

        if ($request->filled('qty')) {
            $newQty = (int) $request->input('qty');
        } else {
            $newQty = (int) $consumable->qty + (int) $request->input('delta', 0);
        }

        // Floor at what's checked out (and never negative); ceiling at the
        // column's max. Mirrors the edit form's `min:$checkedOut` guard.
        $newQty = max($checkedOut, 0, $newQty);
        $newQty = min($newQty, 99999);

        $consumable->qty = $newQty;

        if (! $consumable->save()) {
            return response()->json([
                'status' => 'error',
                'messages' => $consumable->getErrors()->all(),
            ], 422);
        }

        $remaining = (int) $consumable->numRemaining();
        $min = (int) ($consumable->min_amt ?? 0);

        return response()->json([
            'status' => 'success',
            'qty' => (int) $consumable->qty,
            'remaining' => $remaining,
            'min' => $min,
            'state' => $remaining <= 0 ? 'red' : (($min > 0 && $remaining <= $min) ? 'yellow' : 'green'),
        ]);
    }

    public function clone(Consumable $consumable): View
    {
        $this->authorize('create', $consumable);
        $consumable_to_close = $consumable;
        $consumable = clone $consumable_to_close;
        $consumable->id = null;
        $consumable->created_by = null;

        return view('consumables/edit')
            ->with('cloned_model', $consumable_to_close)
            ->with('item', $consumable);
    }
}

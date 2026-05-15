<?php

namespace App\Http\Controllers\Consumables;

use App\Events\CheckoutableCheckedOut;
use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\CheckoutAcceptance;
use App\Models\Consumable;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ConsumableCheckoutController extends Controller
{
    /**
     * Return a view to checkout a consumable to a user.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @see ConsumableCheckoutController::store() method that stores the data.
     * @since [v1.0]
     *
     * @param  int  $id
     */
    public function create($id): View|RedirectResponse
    {

        if ($consumable = Consumable::find($id)) {

            $this->authorize('checkout', $consumable);

            // Make sure the category is valid
            if ($consumable->category) {

                // Make sure there is at least one available to checkout
                if ($consumable->numRemaining() <= 0) {
                    return redirect()->route('consumables.index')
                        ->with('error', trans('admin/consumables/message.checkout.unavailable', ['requested' => 1, 'remaining' => $consumable->numRemaining()]));
                }

                // Return the checkout view
                return view('consumables/checkout', compact('consumable'));
            }

            // Invalid category
            return redirect()->route('consumables.edit', ['consumable' => $consumable->id])
                ->with('error', trans('general.invalid_item_category_single', ['type' => trans('general.consumable')]));
        }

        // Not found
        return redirect()->route('consumables.index')->with('error', trans('admin/consumables/message.does_not_exist'));

    }

    /**
     * Saves the checkout information
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @see ConsumableCheckoutController::create() method that returns the form.
     * @since [v1.0]
     *
     * @param  int  $consumableId
     * @return RedirectResponse
     *
     * @throws AuthorizationException
     */
    public function store(Request $request, $consumableId)
    {
        if (is_null($consumable = Consumable::with('users')->find($consumableId))) {
            return redirect()->route('consumables.index')->with('error', trans('admin/consumables/message.not_found'));
        }

        $this->authorize('checkout', $consumable);

        // If the quantity is not present in the request or is not a positive integer, set it to 1
        $quantity = $request->input('checkout_qty');
        if (! isset($quantity) || ! ctype_digit((string) $quantity) || $quantity <= 0) {
            $quantity = 1;
        }

        // Make sure there is at least one available to checkout
        if ($consumable->numRemaining() <= 0 || $quantity > $consumable->numRemaining()) {
            return redirect()->route('consumables.index')->with('error', trans('admin/consumables/message.checkout.unavailable', ['requested' => $quantity, 'remaining' => $consumable->numRemaining()]));
        }

        $admin_user = auth()->user();

        // A consumable can be checked out to a user (the default) or to an
        // asset — e.g. toner assigned to a specific printer. The target type
        // drives which relation the pivot row lands on and the assigned_type
        // value stored on consumables_users.
        $checkout_to_type = $request->input('checkout_to_type') === 'asset' ? 'asset' : 'user';

        if ($checkout_to_type === 'asset') {
            if (is_null($target = Asset::find($request->input('assigned_asset')))) {
                return redirect()->route('consumables.checkout.show', $consumable)
                    ->with('error', trans('admin/consumables/message.checkout.target_does_not_exist'))->withInput();
            }
            $target_type = Asset::class;
            $relation = $consumable->assets();
        } else {
            if (is_null($target = User::find($request->input('assigned_to')))) {
                return redirect()->route('consumables.checkout.show', $consumable)
                    ->with('error', trans('admin/consumables/message.checkout.user_does_not_exist'))->withInput();
            }
            $target_type = User::class;
            $relation = $consumable->users();
        }

        // Update the consumable data
        $consumable->assigned_to = $target->id;

        // assigned_type is passed explicitly rather than relying on the
        // relation's wherePivot default, so the row is tagged correctly
        // regardless of Laravel's attach() pivot-default behaviour.
        for ($i = 0; $i < $quantity; $i++) {
            $relation->attach($target->id, [
                'consumable_id' => $consumable->id,
                'created_by' => $admin_user->id,
                'assigned_to' => $target->id,
                'assigned_type' => $target_type,
                'note' => $request->input('note'),
            ]);
        }

        $consumable->checkout_qty = $quantity;

        // sign-in-place only applies to user checkouts — an asset cannot sign.
        $sign_in_place = $checkout_to_type === 'user' && $request->boolean('sign_in_place');

        event(new CheckoutableCheckedOut(
            $consumable,
            $target,
            auth()->user(),
            $request->input('note'),
            [],
            $consumable->checkout_qty,
            $sign_in_place,
        ));

        // Helper::getRedirectOption() reads these off the request to build the
        // redirect-to-target URL when redirect_option is 'target'.
        $request->request->add([
            'assigned_user' => $checkout_to_type === 'user' ? $target->id : null,
            'assigned_asset' => $checkout_to_type === 'asset' ? $target->id : null,
        ]);

        session()->put([
            'redirect_option' => $request->input('redirect_option'),
            'checkout_to_type' => $checkout_to_type,
            'sign_in_place' => $sign_in_place,
        ]);

        // When sign_in_place is requested, redirect to the acceptance/signature page
        // so the user can sign in person. The signature is attributed to the target user.
        if ($sign_in_place) {
            $user = $target;
            $acceptance = CheckoutAcceptance::where('checkoutable_type', Consumable::class)
                ->where('checkoutable_id', $consumable->id)
                ->where('assigned_to_id', $user->id)
                ->pending()
                ->latest()
                ->first();

            // If requireAcceptance() is false the listener won't have created one; create it now.
            if (! $acceptance) {
                $acceptance = new CheckoutAcceptance;
                $acceptance->checkoutable()->associate($consumable);
                $acceptance->assignedTo()->associate($user);
                $acceptance->qty = $quantity;
                $acceptance->save();
            }

            session([
                'sign_in_place_acceptance_id' => $acceptance->id,
                'sign_in_place_item_id' => $consumable->id,
                'sign_in_place_resource_type' => 'Consumables',
            ]);

            return redirect()->route('account.accept.item', $acceptance->id)
                ->with('success', trans('admin/consumables/message.checkout.success'));
        }

        // Redirect to the new consumable page
        return Helper::getRedirectOption($request, $consumable->id, 'Consumables')
            ->with('success', trans('admin/consumables/message.checkout.success'));
    }
}

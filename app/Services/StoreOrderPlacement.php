<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\CatalogItem;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\User;
use App\Services\Deployments\WaveMembership;
use App\Services\Settings\Preferences;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Placing a store order, for whichever door it comes through.
 *
 * The storefront form and the API both land here, so a faculty supersede,
 * an early-refresh link, asset provisioning and the requester's email
 * behave the same however the order was placed. Two copies of this would
 * drift, and the first sign would be a missing placeholder asset.
 */
class StoreOrderPlacement
{
    /**
     * The fields an order accepts, shared by both doors.
     *
     * @return array<string, mixed>
     */
    public static function rules(Request $request): array
    {
        return [
            'items' => 'required|array|min:1',
            'items.*.catalog_item_id' => 'required|integer|exists:catalog_items,id',
            'items.*.quantity' => 'required|integer|min:1|max:100',
            'notes' => 'nullable|string|max:65535',
            'refresh_asset_id' => 'nullable|integer',
            'gl_code' => 'nullable|string|max:64',
            // A PO number is letters, digits and simple separators. Anything
            // else has no business in one, and this value is echoed into the
            // procurement Teams digest, where markup would render.
            'department_po_number' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9 .\/_-]*$/'],
            'order_usage' => 'nullable|string|in:assigned,shared',
            // Required only for a cart that will actually be shared. Posting
            // order_usage=shared without the standing to place one is not an
            // error to correct — it is quietly an assigned order — so it must
            // not demand a room first.
            'location_id' => [
                'nullable',
                'integer',
                'exists:locations,id',
                Rule::requiredIf(fn () => $request->input('order_usage') === 'shared'
                    && $request->user()?->canOrderShared()),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{order: ?StoreOrder, error: ?string}  error is a key under admin/store/general
     */
    public function place(User $user, array $validated): array
    {
        // A shared cart — a lab, classroom or team space — only from someone
        // cleared to place them; everyone else's orders are for themselves.
        $shared = ($validated['order_usage'] ?? 'assigned') === 'shared'
            && $user->canOrderShared();

        // Orders from faculty ride the faculty laptop program — its
        // intake and agreement flow picks them up from the queue. A shared
        // cart never does, whoever placed it.
        $isFaculty = ! $shared && $user->groups()
            ->where('permission_groups.name', 'like', '%'.Preferences::get('groups.faculty_match').'%')
            ->exists();

        // An early-refresh order remembers which machine it replaces. The
        // posted id goes through the same guard as the page load — never a
        // shared cart, only their own Staff-catalog machine — and the GL
        // code only means anything in that context.
        $refreshAsset = $shared ? null : $this->refreshAssetFor($user, $validated['refresh_asset_id'] ?? null);
        // The GL code is open to every order, not just early refreshes — any
        // store purchase can be department-funded. Except faculty, who are
        // never asked: the program pays for their laptop, so a code posted
        // against a faculty order came from a stale form, not a decision.
        $glCode = $user->isFacultyProgramMember()
            ? ''
            : trim((string) ($validated['gl_code'] ?? ''));

        // A department that has already taken its requisition through
        // Colleague to a purchase order has finance's approval in hand, so
        // the order skips review and waits only to be sent. Same faculty
        // rule as the GL code: the program pays, so there is no PO to give.
        $departmentPo = $user->isFacultyProgramMember()
            ? ''
            : trim((string) ($validated['department_po_number'] ?? ''));

        // The faculty programme buys one laptop per person, so a second open
        // order is never a second machine — it is the same person changing
        // their mind, or clicking submit twice. Superseding the open one
        // keeps "I picked the wrong model" working (a different choice is
        // welcome, and lands as an edit) while a duplicate cannot stack:
        // three identical orders once produced three placeholder assets
        // that had to be deleted by hand.
        //
        // Only a pending order is superseded. Once approved it may already
        // be with the vendor, and withdrawing it silently under a resubmit
        // would strand a machine that ITS has committed to buying.
        if ($isFaculty) {
            $open = StoreOrder::where('user_id', $user->id)
                ->where('program', 'faculty')
                ->whereIn('status', ['pending', 'approved'])
                ->get();

            if ($open->contains(fn (StoreOrder $existing) => $existing->status === 'approved')) {
                return ['order' => null, 'error' => 'order_already_approved'];
            }

            foreach ($open as $existing) {
                app(StoreOrderAssetProvisioner::class)->release($existing);
                $existing->update(['status' => 'cancelled']);
            }
        }

        $order = DB::transaction(function () use ($user, $validated, $isFaculty, $shared, $refreshAsset, $glCode, $departmentPo) {
            // The wave that invited this order, when there is one. Without it,
            // "who from wave 2 has ordered" is two screens and a name
            // comparison; with it, the wave page can say who is still to act.
            $wave = $isFaculty ? (new WaveMembership)->waveFor($user) : null;

            $order = StoreOrder::create([
                'user_id' => $user->id,
                'status' => $departmentPo !== '' ? 'approved' : 'pending',
                'decided_at' => $departmentPo !== '' ? now() : null,
                'decision_notes' => $departmentPo !== ''
                    ? trans('admin/store/general.preapproved_by_po', ['po' => $departmentPo])
                    : null,
                'program' => $isFaculty ? 'faculty' : null,
                'deployment_wave_id' => $wave?->id,
                'order_usage' => $shared ? 'shared' : 'assigned',
                'location_id' => $shared ? ($validated['location_id'] ?? null) : null,
                'refresh_asset_id' => $refreshAsset?->id,
                'gl_code' => $glCode !== '' ? $glCode : null,
                'department_po_number' => $departmentPo !== '' ? $departmentPo : null,
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($validated['items'] as $line) {
                $item = CatalogItem::inStore()->find($line['catalog_item_id']);

                // A row hidden between page load and submit is dropped, not
                // errored — the rest of the order is still what they meant.
                if (! $item) {
                    continue;
                }

                StoreOrderItem::create([
                    'store_order_id' => $order->id,
                    'catalog_item_id' => $item->id,
                    'description' => $item->name,
                    'vendor_sku' => $item->vendor_sku,
                    'mfr_part_number' => $item->mfr_part_number,
                    'quantity' => (int) $line['quantity'],
                    'unit_cost' => round($item->effectiveCost(), 2),
                ]);
            }

            return $order;
        });

        if ($order->items()->count() === 0) {
            $order->delete();

            return ['order' => null, 'error' => 'order_empty'];
        }

        // The devices on this order become real assets now — A-number, model,
        // requester's name, no serial until CDW ships. Failure here must not
        // undo a placed order, so it logs rather than throws; the queue makes
        // a missing record visible.
        try {
            $provisioned = app(StoreOrderAssetProvisioner::class)
                ->provision($order->load('items.catalogItem', 'user'));

            // Silence here is how two iPads went missing: every unit failed
            // validation, the failure went to a log nobody reads, and the
            // order looked normal for a day. Say plainly how many of the
            // expected records exist, so the line is findable by count
            // rather than by knowing what to grep for.
            $expected = $order->expectedAssetCount();
            if ($provisioned->count() < $expected) {
                Log::error('Store order '.$order->id.' provisioned '
                    .$provisioned->count().' of '.$expected.' expected assets');
            }
        } catch (\Throwable $e) {
            Log::error('Asset provisioning failed for order '.$order->id.': '.$e->getMessage());
        }

        StoreOrderNotifier::requester($order->load('items', 'user'), 'requested');

        return ['order' => $order, 'error' => null];
    }

    /**
     * The machine an early-refresh order replaces — only the placer's own
     * Staff-catalog asset, never someone else's.
     */
    public function refreshAssetFor(User $user, mixed $assetId): ?Asset
    {
        if (! is_numeric($assetId)) {
            return null;
        }

        $asset = Asset::with('model')->find((int) $assetId);

        $mine = $asset
            && (int) $asset->assigned_to === (int) $user->id
            && $asset->assigned_type === User::class;

        return ($mine && $asset->isStaffCatalog()) ? $asset : null;
    }
}

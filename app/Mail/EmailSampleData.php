<?php

namespace App\Mail;

use App\Models\Accessory;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\CatalogItem;
use App\Models\Category;
use App\Models\CheckoutAcceptance;
use App\Models\Component;
use App\Models\Consumable;
use App\Models\DeploymentWave;
use App\Models\Contract;
use App\Models\License;
use App\Models\LicenseSeat;
use App\Models\Location;
use App\Models\Manufacturer;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Statuslabel;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserAgreement;
use Illuminate\Support\Collection;

/**
 * Builds throwaway, **unsaved** sample models used only to render email
 * previews in Settings → Emails. Relations are pre-set with setRelation()
 * so the email blades render without touching real data. Nothing here is
 * persisted; the objects exist for the duration of a single preview render.
 *
 * Used by App\Mail\EmailRegistry. Kept deliberately dumb — just enough
 * shape for the templates to render a representative message.
 */
class EmailSampleData
{
    public function recipient(): User
    {
        return $this->user('Jane', 'Doe', 'jdoe@ecuad.ca');
    }

    public function admin(): User
    {
        return $this->user('Alex', 'Admin', 'assetsadmins@ecuad.ca');
    }

    public function user(string $first, string $last, string $email): User
    {
        $user = new User([
            'first_name' => $first,
            'last_name' => $last,
            'username' => strtolower($first[0].$last),
            'email' => $email,
        ]);
        $user->id = 0;

        return $user;
    }

    public function category(string $name = 'Laptops'): Category
    {
        return new Category([
            'name' => $name,
            'require_acceptance' => 0,
            'use_default_eula' => 0,
            'eula_text' => null,
            'checkin_email' => 1,
        ]);
    }

    public function manufacturer(string $name = 'Apple'): Manufacturer
    {
        return new Manufacturer(['name' => $name]);
    }

    public function location(string $name = 'Main Campus — Tech Services'): Location
    {
        return new Location(['name' => $name]);
    }

    public function assetModel(string $name = 'MacBook Pro 14"'): AssetModel
    {
        $model = new AssetModel(['name' => $name, 'model_number' => 'A2918']);
        $model->setRelation('category', $this->category());
        $model->setRelation('manufacturer', $this->manufacturer());
        $model->setRelation('fieldset', null);

        return $model;
    }

    public function asset(): Asset
    {
        $asset = new Asset([
            'name' => '',
            'asset_tag' => 'ECU-100123',
            'serial' => 'C02ABC123DEF',
        ]);
        $asset->id = 0;
        // Populate the date columns the report accessors read directly out of
        // $attributes (eol_date / next_audit_date / warranty), so the digest
        // emails render a representative row instead of hitting an undefined key.
        $asset->purchase_date = '2024-09-01';
        $asset->warranty_months = 36;
        $asset->asset_eol_date = '2027-09-01';
        $asset->eol_explicit = true;
        $asset->last_audit_date = '2025-07-15';
        $asset->next_audit_date = '2026-07-15';
        $asset->expected_checkin = '2026-07-01';
        $asset->notes = 'Issued under the faculty laptop program.';
        $asset->setRelation('model', $this->assetModel());
        $asset->setRelation('manufacturer', $this->manufacturer());
        // A real (deployable) status so present()->statusMeta / getStatuslabelType()
        // resolve in the request/checkin digests.
        $asset->setRelation('assetstatus', new Statuslabel([
            'name' => 'Deployed', 'deployable' => 1, 'pending' => 0, 'archived' => 0,
        ]));
        $asset->setRelation('status', new Statuslabel([
            'name' => 'Deployed', 'deployable' => 1, 'pending' => 0, 'archived' => 0,
        ]));
        $asset->setRelation('supplier', null);
        // Some blades read ->assignedTo, others ->assignedto — set both keys.
        // assigned_type lets Asset::targetShowRoute() resolve to 'users.show'
        // in the digest blades instead of an empty route name.
        $asset->assigned_type = User::class;
        $asset->setRelation('assignedTo', $this->recipient());
        $asset->setRelation('assignedto', $this->recipient());

        return $asset;
    }

    /** @return Collection<int, Asset> */
    public function assets(int $count = 3): Collection
    {
        $tags = ['ECU-100123', 'ECU-100124', 'ECU-100125'];

        return collect(range(0, $count - 1))->map(function ($i) use ($tags) {
            $asset = $this->asset();
            $asset->asset_tag = $tags[$i] ?? ('ECU-1001'.(26 + $i));

            return $asset;
        });
    }

    public function accessory(): Accessory
    {
        $accessory = new Accessory(['name' => 'USB-C Dock', 'qty' => 25]);
        $accessory->id = 0;
        $accessory->setRelation('category', $this->category('Accessories'));
        $accessory->setRelation('manufacturer', $this->manufacturer('Dell'));
        $accessory->setRelation('location', $this->location());

        return $accessory;
    }

    public function component(): Component
    {
        $component = new Component(['name' => '16GB RAM Module', 'serial' => 'RAM-99812', 'qty' => 50]);
        $component->id = 0;
        $component->setRelation('category', $this->category('Components'));
        $component->setRelation('location', $this->location());

        return $component;
    }

    public function consumable(): Consumable
    {
        $consumable = new Consumable(['name' => 'Toner Cartridge (Black)', 'qty' => 40]);
        $consumable->id = 0;
        $consumable->setRelation('category', $this->category('Consumables'));
        $consumable->setRelation('manufacturer', $this->manufacturer('HP'));

        return $consumable;
    }

    public function license(): License
    {
        $license = new License(['name' => 'Adobe Creative Cloud', 'serial' => 'XXXX-YYYY-ZZZZ-1234', 'seats' => 100]);
        $license->id = 0;
        // termination_date is read straight out of $attributes by the expiring
        // accessors — set it so the expiring-licenses digest renders.
        $license->termination_date = '2026-08-15';
        $license->expiration_date = '2026-08-15';
        $license->purchase_date = '2023-08-15';
        $license->setRelation('category', $this->category('Software'));
        $license->setRelation('manufacturer', $this->manufacturer('Adobe'));

        return $license;
    }

    public function licenseSeat(): LicenseSeat
    {
        $seat = new LicenseSeat([]);
        $seat->id = 0;
        $seat->setRelation('license', $this->license());

        return $seat;
    }

    /** @return Collection<int, License> */
    public function licenses(): Collection
    {
        $names = ['Adobe Creative Cloud', 'Microsoft 365 E5', 'Zoom Enterprise'];

        return collect($names)->map(function ($name) {
            $license = $this->license();
            $license->name = $name;

            return $license;
        });
    }

    public function acceptance(): CheckoutAcceptance
    {
        $acceptance = new CheckoutAcceptance;
        $acceptance->id = 0;
        $acceptance->note = 'Signed at pickup.';
        $acceptance->setRelation('checkoutable', $this->asset());
        $acceptance->setRelation('assignedTo', $this->recipient());

        return $acceptance;
    }

    public function contract(): Contract
    {
        $contract = new Contract([
            'name' => 'Dell Lease FY26 (Laptops)',
            'contract_number' => 'Lease FY26 (Laptops)',
        ]);
        $contract->id = 0;

        return $contract;
    }

    /** @return Collection<int, Contract> */
    public function contracts(): Collection
    {
        return collect([$this->contract()]);
    }

    public function userAgreement(string $type = 'pickup'): UserAgreement
    {
        $agreement = new UserAgreement([
            'agreement_type' => $type,
            'status' => 'pending_signature',
        ]);
        $agreement->id = 0;
        $agreement->setRelation('user', $this->recipient());
        $agreement->setRelation('asset', $this->asset());

        return $agreement;
    }

    // ---- Notification-channel sample data (Phase E3) ----

    /** A stand-in notifiable for report notifications (toMail ignores it). */
    public function notifiable(): User
    {
        return $this->admin();
    }

    /** A recipient user carrying sample assigned inventory (CurrentInventory). */
    public function userWithInventory(): User
    {
        $user = $this->recipient();
        $user->setRelation('assets', $this->assets());
        $user->setRelation('accessories', collect());
        $user->setRelation('consumables', collect());
        $user->setRelation('licenses', collect());

        return $user;
    }

    /** Constructor payload for FirstAdminNotification. */
    public function firstAdminData(): array
    {
        return [
            'email' => 'newadmin@ecuad.ca',
            'first_name' => 'New',
            'last_name' => 'Admin',
            'username' => 'nadmin',
            'password' => 'S3cretSetupPass!',
        ];
    }

    /** Constructor payload for the AcceptanceItem* notifications. */
    public function acceptanceParams(): array
    {
        $asset = $this->asset();

        return [
            'item_tag' => $asset->asset_tag,
            'item_name' => 'MacBook Pro 14"',
            'item_model' => 'MacBook Pro 14"',
            'item_serial' => $asset->serial,
            'item_status' => 'Deployed',
            'accepted_date' => 'Jun 02, 2026',
            'declined_date' => 'Jun 02, 2026',
            'assigned_to' => 'Jane Doe',
            'company_name' => 'Emily Carr University',
            'qty' => 1,
            'note' => 'Signed at pickup.',
            'custom_fields' => [],
            'item' => $asset,
            'user' => $this->recipient(),
        ];
    }

    /** Constructor payload for the asset request notifications. */
    public function requestParams(): array
    {
        return [
            'target' => $this->recipient(),
            'item' => $this->asset(),
            'item_type' => 'asset',
            'item_quantity' => 1,
            'requested_date' => '2026-06-02 09:00:00',
            'note' => 'Needed for onboarding.',
        ];
    }

    /** A store order with two lines, for the store lifecycle emails. */
    public function storeOrder(string $status = 'pending', int $id = 481): StoreOrder
    {
        $supplier = new Supplier(['name' => 'CDW Canada Inc']);

        $line = function (string $description, string $sku, string $mfr, int $qty, float $cost) use ($supplier) {
            $item = new StoreOrderItem([
                'description' => $description,
                'vendor_sku' => $sku,
                'mfr_part_number' => $mfr,
                'quantity' => $qty,
                'unit_cost' => $cost,
            ]);
            $catalogItem = new CatalogItem(['name' => $description, 'product_type' => 'standard', 'price_type' => 'quoted']);
            $catalogItem->setRelation('supplier', $supplier);
            $item->setRelation('catalogItem', $catalogItem);

            return $item;
        };

        $order = new StoreOrder([
            'status' => $status,
            'notes' => 'For the new animation lab — room B2210, needed before September.',
            'decision_notes' => $status === 'declined' ? 'Out of cycle — let\'s revisit this in September.' : null,
        ]);
        $order->id = $id;
        // A real order always has one; the vendor CSV names its file from it.
        $order->created_at = now();
        $order->setRelation('user', $this->recipient());
        $order->setRelation('items', collect([
            $line('MacBook Pro | 14" | M5 Pro | 24GB | 1TB | Black', '854420', 'MGDP4LL/A', 2, 3799.00),
            $line('Apple Studio Display | Standard Glass | Tilt Adj.', '854431', 'MFEW4CL/A', 2, 2100.00),
        ]));

        return $order;
    }

    /**
     * A purchase order with an order on it, quoted and ready to place — the
     * shape RequisitionVendorOrderMail renders. Unsaved like everything else
     * here, with the requisition and its lines set as relations rather than
     * written, so the previewer never touches real data.
     */
    public function purchaseOrder(): PurchaseOrder
    {
        $supplier = new Supplier(['name' => 'CDW Canada Inc']);

        $line = function (string $description, string $sku, string $mfr, int $qty, float $cost) {
            return new RequisitionItem([
                'description' => $description,
                'vendor_sku' => $sku,
                'mfr_part_number' => $mfr,
                'quantity' => $qty,
                'unit_of_measure' => 'EA',
                'unit_cost' => $cost,
                'pst_applicable' => false,
            ]);
        };

        $requisition = new Requisition([
            'title' => 'Foundation Mobile MacBook Labs',
            'status' => 'ordered',
            'requisition_number' => '0017859',
            'printer_comments' => 'Ministry capital funding. PST exempt. Deliver to B1115.',
        ]);
        $requisition->setRelation('items', collect([
            $line('Apple MacBook Air | 13" | M5 | 16GB | 1TB | Silver', '9094662', 'MDH84LL/A', 42, 2150.48),
            $line('AppleCare+ for Schools | 4 Year | 13" MacBook Air', '8154132', 'SLTC2Z/A', 42, 239.20),
            $line('LocknCharge Joey 30 Cart', '8004629', 'LNC9-10559', 2, 2236.32),
        ]));

        $order = new PurchaseOrder([
            'po_number' => 'P0026022',
            'title' => 'Foundation Mobile MacBook Labs - ministry capital',
            'fiscal_year' => 'FY2026-27',
            'funding_account' => 'purchase_curriculum',
            'quote_number' => 'PZFD093',
            'quote_total' => 110202.15,
        ]);
        $order->setRelation('supplier', $supplier);
        $order->setRelation('requisitions', collect([$requisition]));

        return $order;
    }

    /**
     * A wave announcement as one recipient would receive it — the Faculty Laptop
     * Program letter with the merge fields resolved, so the preview shows the
     * shape of a real one rather than a template full of braces.
     */
    public function waveAnnouncement(): DeploymentWaveMail
    {
        $wave = new DeploymentWave([
            'name' => 'Faculty Laptop Program refresh FY2026-27',
            'fiscal_year' => 'FY2026-27',
            'wave_state' => 'planned',
        ]);
        $wave->id = 2;

        $asset = $this->asset();
        $recipient = $this->recipient();

        $context = [
            'recipient' => $recipient->present()->fullName,
            'first_name' => $recipient->first_name,
            'wave' => $wave->name,
            'fiscal_year' => $wave->fiscal_year,
            'device' => 'MacBook Pro 14" (L003336)',
            'device_model' => 'MacBook Pro (14-inch, 2021)',
            'lease_end' => 'December 31, 2026',
            'lease_end_year' => '2026',
            'form_url' => route('forms.show', 'faculty-program'),
            'store_url' => route('store.index'),
        ];

        return new DeploymentWaveMail(
            $wave,
            $recipient,
            trans('admin/deployments/general.announce_faculty_subject'),
            trans('admin/deployments/general.announce_faculty_body'),
            collect([$asset]),
            $context
        );
    }

    /** Low-inventory rows for InventoryAlert (blade reads array keys). */
    public function lowInventoryItems(): Collection
    {
        return collect([
            [
                'id' => 1, 'name' => 'Toner Cartridge (Black)', 'type' => 'consumables', 'remaining' => 0, 'min_amt' => 2,
                'models' => [['name' => 'Ricoh IM C3500', 'manufacturer' => 'Ricoh', 'printers_count' => 6]],
            ],
            [
                'id' => 2, 'name' => 'Toner Cartridge (Cyan)', 'type' => 'consumables', 'remaining' => 1, 'min_amt' => 2,
                'models' => [['name' => 'Ricoh IM C3500', 'manufacturer' => 'Ricoh', 'printers_count' => 6]],
            ],
            ['id' => 3, 'name' => 'USB-C Dock', 'type' => 'accessories', 'remaining' => 1, 'min_amt' => 5],
        ]);
    }
}

<?php

namespace App\Mail;

use App\Models\Accessory;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Category;
use App\Models\Component;
use App\Models\Consumable;
use App\Models\Contract;
use App\Models\License;
use App\Models\LicenseSeat;
use App\Models\Location;
use App\Models\Manufacturer;
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
        $asset->notes = 'Issued under the faculty laptop program.';
        $asset->setRelation('model', $this->assetModel());
        $asset->setRelation('manufacturer', $this->manufacturer());
        $asset->setRelation('status', null);
        $asset->setRelation('supplier', null);
        // Some blades read ->assignedTo, others ->assignedto — set both keys.
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

    public function acceptance(): \App\Models\CheckoutAcceptance
    {
        $acceptance = new \App\Models\CheckoutAcceptance;
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
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Set up the asset-detail field-group taxonomy entirely from code (no GUI):
 * six groups in a fixed render order, and every custom field mapped to one of
 * them. Re-runnable — groups are upserted by slug and field assignments are
 * matched by field name, so it converges whether or not the earlier
 * create_field_groups seed ran. Supersedes the original four-group seed
 * (Specs / Lease & Procurement / Identity / Metadata): "Lease & Procurement"
 * becomes "Procurement", "Metadata" becomes the top-level "Inventory" group,
 * and Management + Networking are added.
 */
class SeedAssetFieldGroups extends Migration
{
    /** Target groups, in render order. [slug => [name, color, icon, sort_order]] */
    private const GROUPS = [
        'inventory'   => ['Inventory',   '#2980b9', 'fas fa-clipboard-list',       0],
        'specs'       => ['Specs',       '#16a085', 'fas fa-microchip',            1],
        'management'  => ['Management',   '#8e44ad', 'fas fa-laptop',              2],
        'networking'  => ['Networking',  '#d35400', 'fas fa-network-wired',        3],
        'procurement' => ['Procurement', '#27ae60', 'fas fa-file-invoice-dollar',  4],
        'identity'    => ['Identity',    '#7f8c8d', 'fas fa-fingerprint',          5],
    ];

    /** Custom-field name => group slug. Names match custom_fields.name exactly. */
    private const FIELD_MAP = [
        // Inventory — how the fleet classifies / locates the device
        'Area'                      => 'inventory',
        'Catalog'                   => 'inventory',
        'Usage'                     => 'inventory',
        'Address'                   => 'inventory',
        'Fleet'                     => 'inventory',
        // Specs — hardware
        'Architecture'              => 'specs',
        'Chip'                      => 'specs',
        'CPU'                       => 'specs',
        'GPU'                       => 'specs',
        'NPU'                       => 'specs',
        'Memory'                    => 'specs',
        'Storage'                   => 'specs',
        'Display'                   => 'specs',
        'Display Resolution'        => 'specs',
        'Colour'                    => 'specs',
        'Version'                   => 'specs',
        // Management — endpoint / MDM
        'Platform'                  => 'management',
        'Device Management Service' => 'management',
        // Networking — hostname + print/queue plumbing
        'Hostname'                  => 'networking',
        'Driver'                    => 'networking',
        'Queue(s)'                  => 'networking',
        'Virtual Queue'             => 'networking',
        'Toner Contract'            => 'networking',
        'Options'                   => 'networking',
        // Procurement — lease / purchase / lifecycle cost
        'Ownership Type'            => 'procurement',
        'Lease Contract ID'         => 'procurement',
        'Lease Contract Name'       => 'procurement',
        'Lease End Date'            => 'procurement',
        'Lease Rent'                => 'procurement',
        'Buyout Cost'               => 'procurement',
        'Invoice Number'            => 'procurement',
        'PO Number'                 => 'procurement',
        'Warranty/Soft Cost'        => 'procurement',
        'Decommission Date'         => 'procurement',
        'License Type'              => 'procurement',
        // Identity — external system IDs
        'Entra ID'                  => 'identity',
        'Intune ID'                 => 'identity',
        'Defender ID'               => 'identity',
        'Object ID'                 => 'identity',
        'Micro ID'                  => 'identity',
        'Identifier'                => 'identity',
        'IMEI'                      => 'identity',
    ];

    public function up()
    {
        // field_groups must exist (create_field_groups migration). Bail quietly
        // if it somehow hasn't run yet rather than throwing.
        if (! \Illuminate\Support\Facades\Schema::hasTable('field_groups')) {
            return;
        }

        // 1. Upsert the six target groups by slug; none collapsed by default.
        foreach (self::GROUPS as $slug => [$name, $color, $icon, $sort]) {
            DB::table('field_groups')->updateOrInsert(
                ['slug' => $slug],
                [
                    'name'                 => $name,
                    'color'                => $color,
                    'icon'                 => $icon,
                    'sort_order'           => $sort,
                    'collapsed_by_default' => false,
                    'active'               => true,
                    'updated_at'           => now(),
                    'created_at'           => now(),
                ]
            );
        }

        // 2. Drop the superseded groups from the original seed (renamed above).
        DB::table('field_groups')->whereIn('slug', ['lease_procurement', 'metadata'])->delete();

        // 3. Map each custom field to its group by name.
        if (\Illuminate\Support\Facades\Schema::hasColumn('custom_fields', 'field_group_id')) {
            $slugToId = DB::table('field_groups')->pluck('id', 'slug');
            foreach (self::FIELD_MAP as $fieldName => $slug) {
                if (isset($slugToId[$slug])) {
                    DB::table('custom_fields')
                        ->where('name', $fieldName)
                        ->update(['field_group_id' => $slugToId[$slug]]);
                }
            }
        }
    }

    public function down()
    {
        // Clear the assignments this migration made; leave the group rows in
        // place (reversing the rename/merge of the original seed isn't useful).
        if (\Illuminate\Support\Facades\Schema::hasColumn('custom_fields', 'field_group_id')) {
            $ids = DB::table('field_groups')
                ->whereIn('slug', array_keys(self::GROUPS))
                ->pluck('id');
            DB::table('custom_fields')->whereIn('field_group_id', $ids)->update(['field_group_id' => null]);
        }
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F2·4 — retire the lease / purchasing custom fields.
 *
 * The 13-field lease cluster now lives entirely in native `assets` columns:
 * reports, the Azure Functions, the REST API, and the asset-detail UI all
 * read and write native, and the values were parity-verified against prod
 * (0 mismatches on 2,876 assets) immediately before this ran. This removes the
 * legacy `_snipeit_*` custom-field columns, their `custom_fields` records, and
 * their fieldset attachments.
 *
 * Resolution is by field NAME (the `_snipeit_*` db_column suffix differs per
 * environment), and every step is guarded so re-running or a fresh/empty DB
 * (the test suite) is a clean no-op. Records are removed with the query builder
 * — NOT Eloquent — so the CustomField `deleting` event does not fire; the
 * column drop is done here, guarded by Schema::hasColumn.
 *
 * NOT auto-reversible: down() throws. Roll back by restoring the pre-migration
 * backup (Snipe native backup + mysqldump) captured immediately before deploy.
 */
return new class extends Migration
{
    /** @var array<int, string> Lease-cluster custom-field display names. */
    private array $leaseFieldNames = [
        'Lease Contract ID',
        'Lease Contract Name',
        'Ownership Type',
        'Lease End Date',
        'Lease Rent',
        'Buyout Cost',
        'Decommission Date',
        'PO Number',
        'Invoice Number',
        'Warranty/Soft Cost',
        'Usage',
        'Area',
        'Book Value',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('custom_fields')) {
            return;
        }

        $fields = DB::table('custom_fields')
            ->whereIn('name', $this->leaseFieldNames)
            ->get(['id', 'db_column']);

        foreach ($fields as $field) {
            // Detach from every fieldset first so the record delete below does
            // not trip a foreign-key constraint.
            if (Schema::hasTable('custom_field_custom_fieldset')) {
                DB::table('custom_field_custom_fieldset')
                    ->where('custom_field_id', $field->id)
                    ->delete();
            }

            // Drop the native `_snipeit_*` column if it is still present.
            if ($field->db_column && Schema::hasColumn('assets', $field->db_column)) {
                Schema::table('assets', function ($table) use ($field) {
                    $table->dropColumn($field->db_column);
                });
            }
        }

        // Finally remove the custom-field records themselves.
        DB::table('custom_fields')
            ->whereIn('name', $this->leaseFieldNames)
            ->delete();
    }

    public function down(): void
    {
        throw new \RuntimeException(
            'drop_lease_custom_fields is not auto-reversible. Restore the '
            .'pre-migration backup (Snipe native backup / mysqldump) taken '
            .'immediately before this migration was deployed.'
        );
    }
};

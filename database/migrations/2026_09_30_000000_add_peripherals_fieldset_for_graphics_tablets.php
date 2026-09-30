<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gives graphics-tablet models a fieldset that carries the Fleet field.
 *
 * Graphics-tablet models had no fieldset at all, so the API refuses any write
 * to their Fleet value ("This field seems to exist, but is not available on
 * this Asset Model's fieldset"). A tablet checked out to a lab workstation
 * belongs to that lab's fleet as much as the workstation does, so the
 * classification that tags the workstation has to be able to tag the tablet.
 *
 * The new "Peripherals" fieldset holds Fleet and nothing else. It is not the
 * Devices fieldset because Devices requires Platform and Catalog, which a
 * tablet has no value for, and a required field left empty blocks every
 * update to the asset.
 *
 * Re-runnable: the fieldset is matched by name, the pivot row by field and
 * fieldset, and only models in the Graphics Tablet category that have no
 * fieldset yet are assigned, so a model an admin has since moved elsewhere is
 * left alone.
 */
return new class extends Migration {
    private const FIELDSET = 'Peripherals';

    private const CATEGORY = 'Graphics Tablet';

    public function up(): void
    {
        $fleet = DB::table('custom_fields')->where('db_column', '_snipeit_fleet_41')->first()
            ?? DB::table('custom_fields')->where('name', 'Fleet')->first();

        if (! $fleet) {
            return;
        }

        $fieldsetId = DB::table('custom_fieldsets')->where('name', self::FIELDSET)->value('id');
        if (! $fieldsetId) {
            $fieldsetId = DB::table('custom_fieldsets')->insertGetId([
                'name'       => self::FIELDSET,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $attached = DB::table('custom_field_custom_fieldset')
            ->where('custom_fieldset_id', $fieldsetId)
            ->where('custom_field_id', $fleet->id)
            ->exists();

        if (! $attached) {
            DB::table('custom_field_custom_fieldset')->insert([
                'custom_fieldset_id' => $fieldsetId,
                'custom_field_id'    => $fleet->id,
                'required'           => 0,
                'order'              => 0,
            ]);
        }

        $categoryIds = DB::table('categories')
            ->where('name', self::CATEGORY)
            ->whereNull('deleted_at')
            ->pluck('id');

        if ($categoryIds->isEmpty()) {
            return;
        }

        DB::table('models')
            ->whereIn('category_id', $categoryIds)
            ->whereNull('fieldset_id')
            ->update(['fieldset_id' => $fieldsetId, 'updated_at' => now()]);
    }

    public function down(): void
    {
        $fieldsetId = DB::table('custom_fieldsets')->where('name', self::FIELDSET)->value('id');
        if (! $fieldsetId) {
            return;
        }

        DB::table('models')->where('fieldset_id', $fieldsetId)->update(['fieldset_id' => null]);
        DB::table('custom_field_custom_fieldset')->where('custom_fieldset_id', $fieldsetId)->delete();
        DB::table('custom_fieldsets')->where('id', $fieldsetId)->delete();
    }
};

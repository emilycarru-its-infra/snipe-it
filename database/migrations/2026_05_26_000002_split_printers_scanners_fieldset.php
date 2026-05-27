<?php

use App\Models\CustomFieldset;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Splits the legacy "Printers & Scanners" fieldset into two:
 *   - renames the existing fieldset to "Printers" (preserving its id, so
 *     all AssetModel.fieldset_id pointers continue to work)
 *   - creates a new sibling "Scanners" fieldset with the same custom-field
 *     attachments, so scanner models can be reassigned via the admin UI
 *     without losing their field schema.
 *
 * This migration deliberately does NOT auto-reassign AssetModels -- choosing
 * which models are scanners vs printers is a human decision that depends on
 * naming conventions per institution. After this migration runs, admins
 * pick scanner models in the AssetModel admin UI and switch fieldset_id.
 *
 * The PrinterUsageService accepts both names ("Printers" and the legacy
 * "Printers & Scanners"), so the per-asset Printing tab keeps working
 * whether or not this migration has run yet.
 *
 * Roadmap: docs/printer-roadmap.md §2.3 (open Q1)
 */
return new class extends Migration {
    public function up(): void
    {
        $legacy = CustomFieldset::query()
            ->where('name', 'Printers & Scanners')
            ->first();

        if ($legacy) {
            $legacy->update(['name' => 'Printers']);
        }

        $printers = CustomFieldset::query()
            ->where('name', 'Printers')
            ->first();

        if (! $printers) {
            return;
        }

        if (CustomFieldset::where('name', 'Scanners')->exists()) {
            return;
        }

        $scanners = CustomFieldset::create(['name' => 'Scanners']);

        $pivotRows = DB::table('custom_field_custom_fieldset')
            ->where('custom_fieldset_id', $printers->id)
            ->get();

        foreach ($pivotRows as $row) {
            DB::table('custom_field_custom_fieldset')->insert([
                'custom_fieldset_id' => $scanners->id,
                'custom_field_id'    => $row->custom_field_id,
                'required'           => $row->required ?? 0,
                'order'              => $row->order ?? 0,
            ]);
        }
    }

    public function down(): void
    {
        $scanners = CustomFieldset::where('name', 'Scanners')->first();

        if ($scanners && $scanners->models()->doesntExist()) {
            DB::table('custom_field_custom_fieldset')
                ->where('custom_fieldset_id', $scanners->id)
                ->delete();
            $scanners->delete();
        }

        $printers = CustomFieldset::where('name', 'Printers')->first();
        if ($printers && ! CustomFieldset::where('name', 'Printers & Scanners')->exists()) {
            $printers->update(['name' => 'Printers & Scanners']);
        }
    }
};

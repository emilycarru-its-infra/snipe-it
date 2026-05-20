<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed stub toner consumables for the HP and Xerox printer fleets so the
 * toner dashboard surfaces them. ECU's Snipe instance had printer asset
 * models for both brands but no consumables linked to them — manually
 * entering each cart adds friction we don't need.
 *
 * Stubs are intentionally minimal: name + category + manufacturer + qty=0
 * + min_amt=1. Procurement fills in actual SKUs, prices, locations later
 * via the standard consumable edit form. The daily scheduler links each
 * stub to the right printer model via the established matching pipeline.
 *
 * Idempotent: skipped if a consumable with the same name already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tonerCategoryId = DB::table('categories')
            ->where('category_type', 'consumable')
            ->where('name', 'Laser Printer Toner')
            ->value('id');

        if (! $tonerCategoryId) {
            // Bail quietly — the install doesn't have ECU's category schema.
            // Nothing to do but also nothing to roll back.
            return;
        }

        // Looking these up by name (case-insensitive) so we don't choke on
        // installs that don't have a manufacturer row called exactly "HP".
        $hpId    = $this->manufacturerId(['HP', 'HP Inc Printers', 'Hewlett-Packard']);
        $xeroxId = $this->manufacturerId(['Xerox']);

        $stubs = [];

        // HP: all monochrome LaserJets. One black cart each.
        if ($hpId) {
            foreach ([
                'LaserJet Enterprise M605n Black Toner',
                'LaserJet M406 Black Toner',
                'LaserJet P3015 Black Toner',
                'LaserJet Pro MFP M426 Black Toner',
                'LaserJet Pro MFP M428dw Black Toner',
                'LaserJet Pro MFP M428fdn Black Toner',
            ] as $name) {
                $stubs[] = ['name' => $name, 'manufacturer_id' => $hpId];
            }
        }

        // Xerox: B600 is mono, VersaLink C505 and C9000 are colour (B/C/M/Y).
        if ($xeroxId) {
            $stubs[] = ['name' => 'VersaLink B600 Black Toner', 'manufacturer_id' => $xeroxId];
            foreach (['C505', 'C9000'] as $model) {
                foreach (['Black', 'Cyan', 'Magenta', 'Yellow'] as $colour) {
                    $stubs[] = [
                        'name'            => "VersaLink {$model} {$colour} Toner",
                        'manufacturer_id' => $xeroxId,
                    ];
                }
            }
        }

        $now = now();
        foreach ($stubs as $stub) {
            $exists = DB::table('consumables')->where('name', $stub['name'])->exists();
            if ($exists) {
                continue;
            }
            DB::table('consumables')->insert([
                'name'            => $stub['name'],
                'category_id'     => $tonerCategoryId,
                'manufacturer_id' => $stub['manufacturer_id'],
                'qty'             => 0,
                'min_amt'         => 1,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        }
    }

    public function down(): void
    {
        // No-op: removing stubs after they've been edited by procurement
        // would lose hand-curated SKUs, prices, and link data.
    }

    /**
     * Resolve the first manufacturer id whose name matches any candidate
     * (case-insensitive). Returns null when no match — caller skips that
     * brand's stubs in that case.
     */
    private function manufacturerId(array $candidates): ?int
    {
        foreach ($candidates as $name) {
            $id = DB::table('manufacturers')
                ->whereRaw('LOWER(name) = ?', [strtolower($name)])
                ->value('id');
            if ($id) {
                return (int) $id;
            }
        }
        return null;
    }
};

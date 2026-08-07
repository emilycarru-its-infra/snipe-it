<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give every status label a note, so the list explains itself.
 *
 * Most already carry one and those are left alone. What was left blank splits
 * into two kinds: a few real states nobody had described, and a set of
 * duplicates and Snipe-IT defaults that were never used. The duplicates get a
 * note saying exactly that rather than an invented purpose — documenting them
 * as though they were meaningful would be worse than the blank.
 *
 * Every label named here holds zero assets, including soft-deleted ones, except
 * Storage, which holds 8. Nothing is deleted: removing the dead labels is a
 * separate decision, and their notes now say which they are.
 */
return new class extends Migration
{
    /**
     * name => note. Applied only where the note is currently blank, so a label
     * someone has since described by hand is never overwritten. Where two
     * labels share a name, the blank one is by definition the unused copy.
     */
    private const NOTES = [
        // Real states that simply had not been written down.
        'Active (Decommission)' => 'Assets still in use but slated for decommissioning — the working state before Processing Return, Processing Donation or Processing Recycling.',
        'Broken (Unrepairable)' => 'Assets found to be beyond economical repair and awaiting disposal.',
        'Storage' => 'Assets held in storage: not deployed to anyone and not disposed of, so out of service but still owned.',

        // Snipe-IT defaults this estate replaced with its own vocabulary.
        'In Use' => 'Snipe-IT default, superseded by "Active". Unused — holds no assets and can be deleted.',
        'Inventoried' => 'Snipe-IT default, superseded by "New (Inventoried)". Unused — holds no assets and can be deleted.',

        // Accidental duplicates. Kept only so nothing silently disappears.
        'Broken 2' => 'Duplicate label, never used. Holds no assets and can be deleted.',
        'Broken (Send to Repair) 2' => 'Duplicate label, never used — see "Damaged". Holds no assets and can be deleted.',
        'New (Inventoried)' => 'Duplicate of the "New (Inventoried)" label in use. Holds no assets and can be deleted.',
        'New (Provisioned) 2' => 'Duplicate of "New (Provisioned)", never used. Holds no assets and can be deleted.',
        'Stolen 2' => 'Duplicate of "Stolen", never used. Holds no assets and can be deleted.',
    ];

    public function up(): void
    {
        foreach (self::NOTES as $name => $note) {
            DB::table('status_labels')
                ->where('name', $name)
                ->where(fn ($q) => $q->whereNull('notes')->orWhere('notes', ''))
                ->update(['notes' => $note, 'updated_at' => now()]);
        }

        // The second "Donated" is the odd one out: it carries the same note as
        // the label actually in use, so it reads as legitimate while holding
        // nothing. Say which copy it is.
        $duplicateDonated = DB::table('status_labels')
            ->where('name', 'Donated')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('assets')->whereColumn('assets.status_id', 'status_labels.id'))
            ->value('id');

        if ($duplicateDonated) {
            DB::table('status_labels')->where('id', $duplicateDonated)->update([
                'notes' => 'Duplicate of the "Donated" label in use. Holds no assets and can be deleted.',
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('status_labels')
            ->whereIn('name', array_keys(self::NOTES))
            ->orWhere('notes', 'like', 'Duplicate of the "Donated"%')
            ->update(['notes' => null]);
    }
};

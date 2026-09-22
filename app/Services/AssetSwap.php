<?php

namespace App\Services;

use App\Enums\ActionType;
use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\CustomField;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Exchanges the role of two computers in one step: everything that says what
 * a machine *is for* — its name, where it sits, who has it, how it is
 * catalogued — moves to the other machine, while everything that says what
 * the machine *is* (tag, serial, model, platform, purchase and lease facts)
 * stays with the hardware.
 *
 * Without this, swapping two machines means renaming one to a throwaway name
 * first, checking both in, renaming, checking both out again and saving each
 * field separately. Here both assets are written in one transaction, and each
 * gets one history entry naming the other so the timeline reads as a swap
 * rather than a scatter of unrelated edits.
 */
class AssetSwap
{
    /** Native `assets` columns that belong to the role, not the hardware. */
    public const NATIVE_FIELDS = [
        'name',
        'status_id',
        'location_id',
        'rtd_location_id',
        'lease_area',
        'lease_usage',
        'assigned_to',
        'assigned_type',
    ];

    /**
     * Custom fields that belong to the role, by display name. Resolved to
     * their `_snipeit_*` column at runtime because the suffix differs per
     * environment. A name with no matching field is simply skipped.
     */
    public const CUSTOM_FIELDS = [
        'Catalog',
        'Hostname',
        'Fleet',
    ];

    /**
     * Every column the swap exchanges, keyed by column with a display label.
     *
     * @return array<string, string> column => label
     */
    public function columns(): array
    {
        $columns = array_combine(self::NATIVE_FIELDS, self::NATIVE_FIELDS);

        $customs = CustomField::whereIn('name', self::CUSTOM_FIELDS)->get();

        foreach ($customs as $field) {
            if ($field->db_column && Schema::hasColumn('assets', $field->db_column)) {
                $columns[$field->db_column] = $field->name;
            }
        }

        return $columns;
    }

    /**
     * Why these two assets cannot be swapped, as a translation key, or null
     * when they can.
     */
    public function refusal(Asset $asset, Asset $other): ?string
    {
        if ($asset->is($other)) {
            return 'admin/hardware/swap.error_same_asset';
        }

        if ($asset->trashed() || $other->trashed()) {
            return 'admin/hardware/swap.error_deleted';
        }

        // An asset checked out to the other one would end up checked out to
        // itself once the assignments cross.
        if ($this->isAssignedTo($asset, $other) || $this->isAssignedTo($other, $asset)) {
            return 'admin/hardware/swap.error_assigned_to_each_other';
        }

        return null;
    }

    /**
     * Swap the two assets. Returns the per-asset changes that were applied,
     * keyed by asset id, as `column => ['old' => …, 'new' => …]`.
     *
     * @return array<int, array<string, array{old: mixed, new: mixed}>>
     *
     * @throws ValidationException when either asset fails validation after
     *                             the swap; nothing is written in that case
     */
    public function swap(Asset $asset, Asset $other, ?string $note = null): array
    {
        $columns = array_keys($this->columns());

        return DB::transaction(function () use ($asset, $other, $columns, $note) {
            $before = [
                $asset->id => $asset->only($columns),
                $other->id => $other->only($columns),
            ];

            $changes = [
                $asset->id => $this->diff($before[$asset->id], $before[$other->id]),
                $other->id => $this->diff($before[$other->id], $before[$asset->id]),
            ];

            // A field flagged unique (Hostname, typically) would fail
            // validation on the first save while the second asset still
            // holds the value. Clear those on the second asset first, below
            // the model layer so it logs and announces nothing on its own.
            $unique = array_filter(
                $this->uniqueColumns($columns),
                fn ($column) => $before[$asset->id][$column] != $before[$other->id][$column],
            );
            if ($unique) {
                DB::table('assets')->where('id', $other->id)->update(array_fill_keys($unique, null));
            }

            $this->apply($asset, $before[$other->id]);
            $this->apply($other, $before[$asset->id]);

            $this->logSwap($asset, $other, $changes[$asset->id], $note);
            $this->logSwap($other, $asset, $changes[$other->id], $note);
            $this->logAssignment($asset, $other, $before, $note);
            $this->logAssignment($other, $asset, $before, $note);

            return $changes;
        });
    }

    /**
     * What each asset would look like after the swap, for the confirmation
     * page. Nothing is written.
     *
     * @return array<string, array{label: string, asset: mixed, other: mixed}>
     */
    public function preview(Asset $asset, Asset $other): array
    {
        $rows = [];

        foreach ($this->columns() as $column => $label) {
            $rows[$column] = [
                'label' => $label,
                'asset' => $asset->{$column},
                'other' => $other->{$column},
            ];
        }

        return $rows;
    }

    private function apply(Asset $asset, array $values): void
    {
        $asset->forceFill($values);
        $asset->skipUpdateLog = true;
        $asset->setRules($asset->getRules() + $asset->customFieldValidationRules());

        if (! $asset->save()) {
            throw ValidationException::withMessages($asset->getErrors()->toArray());
        }
    }

    /**
     * One `update` entry on the asset's timeline carrying the field diff and
     * a note naming the other machine. The observer's own update entry is
     * suppressed for the swap: it skips any save that moves `assigned_to`,
     * so it would log some swaps and not others.
     */
    private function logSwap(Asset $asset, Asset $other, array $changed, ?string $note): void
    {
        if (empty($changed)) {
            return;
        }

        $log = new Actionlog;
        $log->item_type = Asset::class;
        $log->item_id = $asset->id;
        $log->target_type = Asset::class;
        $log->target_id = $other->id;
        $log->setAttribute('created_by', auth()->id());
        $log->action_date = now();
        $log->log_meta = json_encode($changed);
        $log->note = $this->note($other, $note);
        $log->logaction(ActionType::Update);
    }

    /**
     * Keep the assignee's own history right: whoever had `$asset` now has
     * `$other`, so record a check-in from the old machine and a checkout of
     * the new one against them. No acceptance or notification is sent — the
     * person is not receiving anything new to sign for, just a different box.
     */
    private function logAssignment(Asset $asset, Asset $other, array $before, ?string $note): void
    {
        $type = $before[$asset->id]['assigned_type'] ?? null;
        $id = $before[$asset->id]['assigned_to'] ?? null;

        if (! $type || ! $id) {
            return;
        }

        foreach ([[$asset, ActionType::CheckinFrom], [$other, ActionType::Checkout]] as [$item, $action]) {
            $log = new Actionlog;
            $log->item_type = Asset::class;
            $log->item_id = $item->id;
            $log->target_type = $type;
            $log->target_id = $id;
            $log->setAttribute('created_by', auth()->id());
            $log->action_date = now();
            $log->note = $this->note($item->is($asset) ? $other : $asset, $note);
            $log->logaction($action);
        }
    }

    private function note(Asset $other, ?string $note): string
    {
        $line = trans('admin/hardware/swap.log_note', ['asset' => $other->asset_tag]);

        return $note ? $line.' — '.$note : $line;
    }

    /**
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function diff(array $from, array $to): array
    {
        $changed = [];

        foreach ($from as $column => $value) {
            if ($value != $to[$column]) {
                $changed[$column] = ['old' => $value, 'new' => $to[$column]];
            }
        }

        return $changed;
    }

    /**
     * @return list<string>
     */
    private function uniqueColumns(array $columns): array
    {
        return CustomField::whereIn('db_column', $columns)
            ->where('is_unique', 1)
            ->pluck('db_column')
            ->all();
    }

    private function isAssignedTo(Asset $asset, Asset $target): bool
    {
        return $asset->assigned_type === Asset::class && (int) $asset->assigned_to === $target->id;
    }
}

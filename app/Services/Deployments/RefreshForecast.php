<?php

namespace App\Services\Deployments;

use App\Models\Asset;
use App\Models\Category;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\DeploymentItem;
use App\Models\LeaseDecision;
use App\Models\Manufacturer;
use App\Models\Statuslabel;
use App\Models\Supplier;
use App\Services\FiscalYear;
use App\Services\Settings\Preferences;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Auto-collection of devices due for refresh in a fiscal year — the
 * headline E1 feature behind /reports/deployments/planning. Replaces Rod
 * manually flipping devices to a stopgap "Active (Lease End)" status: it
 * sweeps assets whose native EOL date OR (if present) "Lease End Date"
 * custom field lands inside a fiscal year, and lets a tech bulk-add them to
 * a deployment wave as replacement items.
 *
 * The FY helpers below are kept as thin aliases of App\Services\FiscalYear,
 * which owns the start month, because callers across the app use them.
 *
 * Devices on a "funded replacement" status (the status.funded_replacement
 * preference) are a funded decision, not a prediction: they join the current
 * fiscal year's forecast regardless of their dates.
 */
class RefreshForecast
{
    /**
     * Canonicalize a fiscal-year string to `FY2025-26`, or null for an
     * empty / "all" / unparseable input.
     */
    public static function normalizeFy(?string $fy): ?string
    {
        return FiscalYear::normalize($fy);
    }

    /** The start calendar year of a canonical FY label (FY2025-26 -> 2025), or null. */
    public static function fiscalYearStartYear(?string $fy): ?int
    {
        return FiscalYear::startYearOf($fy);
    }

    /**
     * The [start, end] Carbon bounds of a fiscal year, or null for an
     * unparseable / "all" FY.
     */
    public static function fiscalYearRange(?string $fy): ?array
    {
        return FiscalYear::range($fy);
    }

    /** The FY label a 'Y-m-d' (or m/d/Y, Y/m/d, d/m/Y) date string falls into, or null. */
    public static function fiscalYearFromEndDate(?string $endDateStr): ?string
    {
        return FiscalYear::fromDateString($endDateStr);
    }

    /**
     * The native `lease_end_date` column (mirrored from the "Lease End Date"
     * custom field), or null in environments where it hasn't been migrated in
     * yet. Every consumer MUST guard against null before touching the column.
     */
    public static function leaseEndColumn(): ?string
    {
        return Schema::hasColumn('assets', 'lease_end_date') ? 'lease_end_date' : null;
    }

    /**
     * Distinct FY labels we have refresh candidates for, derived from
     * assets.asset_eol_date and (if present) the Lease End Date custom
     * field. Sorted ascending (oldest first), always including the current + next FY so
     * an empty board is still usable.
     */
    public function availableFiscalYears(): array
    {
        $labels = [];

        $eolDates = Asset::query()
            ->whereNotNull('asset_eol_date')
            ->pluck('asset_eol_date');
        foreach ($eolDates as $d) {
            if ($fy = self::fiscalYearFromEndDate((string) $d)) {
                $labels[$fy] = true;
            }
        }

        $leaseCol = self::leaseEndColumn();
        if ($leaseCol !== null) {
            $leaseDates = Asset::query()
                ->whereNotNull($leaseCol)
                ->where($leaseCol, '!=', '')
                ->pluck($leaseCol);
            foreach ($leaseDates as $d) {
                if ($fy = self::fiscalYearFromEndDate((string) $d)) {
                    $labels[$fy] = true;
                }
            }
        }

        // Always offer current + next FY.
        $startYear = FiscalYear::currentStartYear();
        foreach ([$startYear, $startYear + 1] as $sy) {
            $labels[FiscalYear::label($sy)] = true;
        }

        $out = array_keys($labels);
        sort($out);

        return $out;
    }

    /**
     * Assets to refresh in $fy: not archived, whose native EOL date OR (if
     * present) the Lease End Date custom field lands in the FY window, and
     * that aren't already on a deployment_item (as asset or replacement),
     * so they're never double-added. Each row is annotated with
     * refresh_reason ('eol'|'lease'|'both') and source_date (Y-m-d). Returns
     * an empty collection when $fy is null — a FY choice is required.
     */
    /**
     * Categories outside the device capital plan (displays, printers,
     * scanners — see config/ecu.php and AB#4473) never enter the forecast,
     * even though they carry lifecycle EOL dates for operations. Assets
     * with no category at all pass through: unclassified is not excluded.
     *
     * @param  Builder<Asset>  $query
     */
    private function excludeNonPlanCategories($query): void
    {
        $excluded = (array) config('ecu.forecast_excluded_categories', []);
        if ($excluded === []) {
            return;
        }

        $query->whereDoesntHave('model.category', fn ($q) => $q->whereIn('name', $excluded));
    }

    /**
     * Assets and lease contracts we are keeping, as [assetIds,
     * contractReferences]. A decision recorded against the contract covers
     * every device on it; one recorded against an asset covers just that
     * device.
     *
     * Retained counts alongside bought out: both end with the equipment
     * staying, which is the only thing the forecast cares about — a kept
     * device needs no replacement budgeting whether we paid to keep it or
     * the lease-to-own term simply ran out.
     *
     * @return array{0: array<int, int>, 1: array<int, string>}
     */
    private function approvedBuyouts(): array
    {
        $decisions = LeaseDecision::query()
            ->whereIn('decision_type', ['buyout', 'retain'])
            ->whereIn('status', ['approved', 'completed'])
            ->get(['asset_id', 'contract_reference']);

        return [
            $decisions->pluck('asset_id')->filter()->unique()->values()->all(),
            $decisions->whereNull('asset_id')->pluck('contract_reference')
                ->filter()->unique()->values()->all(),
        ];
    }

    public function forFiscalYear(?string $fy): Collection
    {
        $range = self::fiscalYearRange($fy);
        if ($range === null) {
            return collect();
        }

        [$start, $end] = $range;
        $startStr = $start->toDateString();
        $endStr = $end->toDateString();
        $leaseCol = self::leaseEndColumn();

        // A lease end is a return window, and a return window is what makes
        // the lease date a refresh trigger. Once a buyout is approved the
        // device is ours and stays in service, so its lease end says nothing
        // about when it should be replaced — only our own End of Life does.
        // Without this, a bought-out device is stuck in the FY its lease
        // happened to end in and cannot be planned into any other year.
        [$buyoutAssetIds, $buyoutContractRefs] = $this->approvedBuyouts();

        $query = Asset::query()
            ->NotArchived()
            ->tap(fn ($q) => $this->excludeNonPlanCategories($q))
            ->with(['model.refreshCatalogItem', 'model.category', 'status', 'location', 'supplier'])
            ->where(function ($q) use ($fy, $start, $end, $leaseCol, $startStr, $endStr, $buyoutAssetIds, $buyoutContractRefs) {
                $q->whereBetween('asset_eol_date', [$start, $end]);

                // Funded replacements carry no FY of their own and read
                // "this year", so they join the current FY's forecast only.
                if (self::normalizeFy($fy) === FiscalYear::current()) {
                    $q->orWhereIn('assets.status_id', Preferences::statusIds('status.funded_replacement') ?: [-1]);
                }

                if ($leaseCol !== null) {
                    // Native lease_end_date is a DATE; 'Y-m-d' bounds compare fine.
                    $q->orWhere(function ($lease) use ($leaseCol, $startStr, $endStr, $buyoutAssetIds, $buyoutContractRefs) {
                        $lease->whereBetween($leaseCol, [$startStr, $endStr]);

                        if (! empty($buyoutAssetIds)) {
                            $lease->whereNotIn('assets.id', $buyoutAssetIds);
                        }

                        if (! empty($buyoutContractRefs)) {
                            $lease->where(function ($contract) use ($buyoutContractRefs) {
                                $contract->whereNull('lease_contract_id')
                                    ->orWhereNotIn('lease_contract_id', $buyoutContractRefs);
                            });
                        }
                    });
                }
            });

        // Exclude assets already tracked by a deployment item (either as
        // the incoming device or the device being replaced).
        $tracked = DeploymentItem::query()
            ->whereNotNull('asset_id')
            ->pluck('asset_id')
            ->merge(
                DeploymentItem::query()->whereNotNull('replaces_asset_id')->pluck('replaces_asset_id')
            )
            ->unique()
            ->all();

        if (! empty($tracked)) {
            $query->whereNotIn('assets.id', $tracked);
        }

        // Deferrals move a device between planning years: an active extend
        // decision stamped with a target FY takes the device out of the
        // year its dates put it in, and drops it into the target year's
        // list instead — where it surfaces with reason "deferred".
        $deferrals = LeaseDecision::query()
            ->where('decision_type', 'extend')
            ->whereNotNull('deferred_to_fy')
            ->whereNotNull('asset_id')
            ->whereIn('status', ['pending', 'approved'])
            ->pluck('deferred_to_fy', 'asset_id');

        $deferredAway = $deferrals->reject(fn ($target) => $target === self::normalizeFy($fy))->keys()->all();
        if (! empty($deferredAway)) {
            $query->whereNotIn('assets.id', $deferredAway);
        }

        $assets = $query->orderBy('asset_eol_date')->orderBy('name')->get();

        // Devices pushed INTO this year join the list even though their
        // own dates point elsewhere.
        $deferredHereIds = $deferrals->filter(fn ($target) => $target === self::normalizeFy($fy))->keys()
            ->diff($assets->pluck('id'))->diff(collect($tracked))->values()->all();
        if (! empty($deferredHereIds)) {
            $deferredIn = Asset::query()
                ->NotArchived()
                ->tap(fn ($q) => $this->excludeNonPlanCategories($q))
                ->with(['model.refreshCatalogItem', 'model.category', 'status', 'location', 'supplier'])
                ->whereIn('assets.id', $deferredHereIds)
                ->get();
            $assets = $assets->concat($deferredIn);
        }

        // Tie the money side in: a lease decision recorded in procurement
        // (per asset, or for the device's whole lease contract via
        // lease_contract_id) changes what "due for refresh" means — e.g. a
        // retained lease-to-own has its refresh budget redirected, so the
        // planner must see the decision right on the candidate row.
        $contractIds = $assets->pluck('lease_contract_id')->filter()->unique()->values()->all();
        $decisions = LeaseDecision::query()
            ->where('status', '!=', 'cancelled')
            ->where(function ($q) use ($contractIds, $assets) {
                $q->whereIn('contract_reference', $contractIds ?: ['-'])
                    ->orWhereIn('asset_id', $assets->pluck('id')->all() ?: [-1]);
            })
            ->get();
        $decisionByAsset = $decisions->whereNotNull('asset_id')->keyBy('asset_id');
        $decisionByContract = $decisions->whereNull('asset_id')->keyBy('contract_reference');

        return $assets->map(function (Asset $asset) use ($start, $end, $leaseCol, $startStr, $endStr, $decisionByAsset, $decisionByContract) {
            $eolIn = false;
            if ($asset->asset_eol_date) {
                $eol = Carbon::parse($asset->asset_eol_date);
                $eolIn = $eol->betweenIncluded($start, $end);
            }

            $leaseIn = false;
            $leaseVal = ($leaseCol !== null) ? (string) ($asset->{$leaseCol} ?? '') : '';
            if ($leaseVal !== '') {
                $leaseIn = ($leaseVal >= $startStr && $leaseVal <= $endStr);
            }

            $eolStr = $asset->asset_eol_date ? Carbon::parse($asset->asset_eol_date)->toDateString() : '';

            // When both dates land in the FY, the lease end wins — that's
            // the contractual return window. The one exception: an End of
            // Life we set EARLIER than the lease end is our own call to
            // replace sooner (a 5-year lease whose device only serves 4),
            // so the earlier End of Life is the operative date.
            $reason = match (true) {
                $eolIn && $leaseIn => ($eolStr !== '' && $eolStr < $leaseVal) ? 'eol' : 'lease',
                $leaseIn => 'lease',
                ! $eolIn && Preferences::statusMatches('status.funded_replacement', $asset->status?->name) => 'funded',
                default => 'eol',
            };

            $sourceDate = $reason === 'lease' ? $leaseVal : ($eolStr ?: $leaseVal);

            // Transient annotations consumed by the forecast view + addFromForecast.
            $asset->refresh_reason = $reason;
            $asset->source_date = $sourceDate;

            $decision = $decisionByAsset->get($asset->id)
                ?: ($asset->lease_contract_id ? $decisionByContract->get($asset->lease_contract_id) : null);
            $asset->lease_decision_label = $decision
                ? trim(ucfirst((string) ($decision->decision_type ?: 'decision')).' — '.($decision->status ?: 'pending'))
                : null;
            $asset->lease_decision_note = $decision?->notes;

            return $asset;
        })->each(function (Asset $asset) use ($deferrals, $fy) {
            // A device pushed into this year carries the deferral as its
            // reason — its own dates say another year, the decision says
            // this one, and the decision is what the planner is reading.
            if (($deferrals[$asset->id] ?? null) === self::normalizeFy($fy)) {
                $asset->refresh_reason = 'deferred';
            }
        });
    }

    /**
     * Early-renewal mode: any criteria supplied drop the EOL/lease window
     * entirely and list every non-archived device matching the ANDed
     * predicates — how a subset of an active contract gets slotted in for
     * an early refresh (moved here from the retired procurement forecast
     * page; the criteria and their query are unchanged).
     *
     * @param  array<int, array{field: string, value: string}>  $criteria
     * @return Collection<int, Asset>
     */
    public function byCriteria(array $criteria): Collection
    {
        if ($criteria === []) {
            return collect();
        }

        $query = Asset::query()
            ->NotArchived()
            ->tap(fn ($q) => $this->excludeNonPlanCategories($q))
            ->with(['model.refreshCatalogItem', 'model.category', 'status', 'location', 'supplier']);

        foreach ($criteria as $criterion) {
            $this->applyCriterion($query, $criterion['field'], $criterion['value']);
        }

        return $query->orderBy('asset_eol_date')->orderBy('name')->get()
            ->map(function (Asset $asset) {
                $asset->refresh_reason = 'criteria';
                $asset->source_date = $asset->asset_eol_date
                    ? Carbon::parse($asset->asset_eol_date)->toDateString()
                    : (string) ($asset->lease_end_date ?? '');
                $asset->lease_decision_label = null;
                $asset->lease_decision_note = null;

                return $asset;
            });
    }

    /**
     * Criteria rows off the query string, validated against the live field
     * allow-list so an arbitrary `cf:` value can't reach the query builder.
     *
     * @return array<int, array{field: string, value: string}>
     */
    public function criteriaFromRequest(Request $request): array
    {
        $raw = $request->query('criteria', []);
        if (! is_array($raw)) {
            return [];
        }

        $allowed = $this->filterFields();
        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $field = (string) ($row['field'] ?? '');
            $value = trim((string) ($row['value'] ?? ''));
            if ($field === '' || $value === '' || ! isset($allowed[$field])) {
                continue;
            }
            $out[] = ['field' => $field, 'value' => $value];
        }

        return $out;
    }

    /**
     * The fields a criterion can target: curated asset taxonomies plus
     * every custom field (keyed `cf:<db_column>`).
     *
     * @return array<string, string>
     */
    public function filterFields(): array
    {
        $fields = [
            'category' => trans('general.category'),
            'manufacturer' => trans('general.manufacturer'),
            'model' => trans('general.asset_model'),
            'status' => trans('general.status'),
            'supplier' => trans('general.supplier'),
            'company' => trans('general.company'),
        ];

        foreach (CustomField::orderBy('name')->get() as $field) {
            if ($field->db_column) {
                $fields['cf:'.$field->db_column] = $field->name;
            }
        }

        return $fields;
    }

    /**
     * Known values per field, for the criteria builder's datalists.
     * Custom-field values come from the assets that carry them (capped —
     * some fields are high-cardinality); Model stays free-text.
     *
     * @return array<string, array<int, string>>
     */
    public function filterValues(): array
    {
        $values = [
            'category' => Category::where('category_type', 'asset')->orderBy('name')->pluck('name')->all(),
            'manufacturer' => Manufacturer::orderBy('name')->pluck('name')->all(),
            'status' => Statuslabel::orderBy('name')->pluck('name')->all(),
            'supplier' => Supplier::orderBy('name')->pluck('name')->all(),
            'company' => Company::orderBy('name')->pluck('name')->all(),
        ];

        foreach (CustomField::orderBy('name')->get() as $field) {
            if (! $field->db_column) {
                continue;
            }
            $values['cf:'.$field->db_column] = Asset::query()
                ->whereNotNull($field->db_column)
                ->where($field->db_column, '!=', '')
                ->distinct()
                ->orderBy($field->db_column)
                ->limit(200)
                ->pluck($field->db_column)
                ->all();
        }

        return $values;
    }

    /**
     * One criterion onto the asset query. Relation fields match by name;
     * custom fields match the generated column, validated against the
     * live allow-list before touching the builder.
     *
     * @param  Builder<Asset>  $query
     */
    private function applyCriterion($query, string $field, string $value): void
    {
        switch ($field) {
            case 'category':
                $query->whereHas('model.category', fn ($q) => $q->where('name', $value));
                break;
            case 'manufacturer':
                $query->whereHas('model.manufacturer', fn ($q) => $q->where('name', $value));
                break;
            case 'model':
                $query->whereHas('model', fn ($q) => $q->where('name', $value));
                break;
            case 'status':
                $query->whereHas('status', fn ($q) => $q->where('name', $value));
                break;
            case 'supplier':
                $query->whereHas('supplier', fn ($q) => $q->where('name', $value));
                break;
            case 'company':
                $query->whereHas('company', fn ($q) => $q->where('name', $value));
                break;
            default:
                if (str_starts_with($field, 'cf:')) {
                    $column = substr($field, 3);
                    $allowed = CustomField::pluck('db_column')->filter()->all();
                    if (in_array($column, $allowed, true)) {
                        $query->where($column, $value);
                    }
                }
        }
    }
}

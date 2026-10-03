<?php

namespace App\Services\Leasing;

use App\Enums\ActionType;
use App\Mail\LeasePickupRequestMail;
use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\EmailTemplate;
use App\Models\LeasePickup;
use App\Models\Statuslabel;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Bundles devices waiting to go back into one pickup per lessor and asks that
 * lessor to collect them — the "Request pickup" action on the decommissioning
 * lane's returns card.
 *
 * The request is written to be the only message the lessor needs: every
 * device by tag, serial and schedule, the counts per schedule, when we would
 * like the truck, and the standing site details. A request that carried only
 * a count used to cost a week of replies asking for the rest.
 *
 * Addressing follows the buyout request: To this lessor's contact email and
 * its own extra lease contacts, never another lessor's; Cc the team list and
 * whoever asked. LessorGuard skips a device filed under the wrong lessor and
 * refuses a recipient list that reaches outside this lessor.
 */
class PickupRequester
{
    /**
     * Open one pickup per lessor for the given assets and mail each lessor.
     *
     * @param  EloquentCollection<int, Asset>  $assets
     * @return array{pickups: Collection<int, LeasePickup>, skipped: array<string, string>}
     *                                                                                      skipped is asset tag => translation key
     */
    public function request(EloquentCollection $assets, ?User $requester, ?string $preferredDates = null, ?string $notes = null): array
    {
        $assets->loadMissing(['lessor', 'model.manufacturer', 'status']);
        $onOpenPickup = $this->assetIdsOnOpenPickups();
        $skipped = [];

        $guard = app(LessorGuard::class);

        $eligible = $assets->filter(function (Asset $asset) use ($onOpenPickup, &$skipped, $guard) {
            $reason = match (true) {
                ! $asset->lessor => 'admin/deployments/general.pickup_skip_no_lessor',
                ! filled($asset->lessor->email) => 'admin/deployments/general.pickup_skip_no_email',
                ! $guard->assetMatchesContract($asset) => 'admin/deployments/general.pickup_skip_lessor_conflict',
                in_array($asset->id, $onOpenPickup, true) => 'admin/deployments/general.pickup_skip_already',
                default => null,
            };
            if ($reason) {
                $skipped[$asset->asset_tag ?: '#'.$asset->id] = $reason;
            }

            return $reason === null;
        });

        $pickups = collect();
        foreach ($eligible->groupBy('lessor_id') as $group) {
            $lessor = $group->first()->lessor;

            $to = array_values(array_unique(array_filter(array_merge(
                [$lessor->email],
                $lessor->leaseEmailList()
            ))));
            $cc = EmailTemplate::ccFor('request.lease_pickup', config('leasing.pickup_request_cc'));
            if ($requester && filled($requester->email)) {
                $cc[] = $requester->email;
            }
            $cc = array_values(array_diff(array_unique(array_filter($cc)), $to));

            // Nobody outside the university but this lessor may see its
            // lease facts; a CC saved for the other lessor stops it here,
            // before a pickup is opened.
            if ($guard->foreignRecipients($lessor, array_merge($to, $cc))) {
                foreach ($group as $asset) {
                    $skipped[$asset->asset_tag ?: '#'.$asset->id] = 'admin/deployments/general.pickup_skip_foreign_recipient';
                }

                continue;
            }

            $pickup = DB::transaction(function () use ($group, $lessor, $requester, $preferredDates, $notes) {
                $pickup = LeasePickup::create([
                    'lessor_id' => $lessor->id,
                    'requested_by' => $requester?->id,
                    'status' => 'requested',
                    'requested_at' => now(),
                    'preferred_dates' => $preferredDates,
                    'notes' => $notes,
                ]);
                $pickup->assets()->attach($group->pluck('id')->all());

                return $pickup;
            });
            $pickup->load(['assets.model.manufacturer', 'lessor', 'requester']);

            Mail::to($to)->cc($cc)->send(new LeasePickupRequestMail($pickup));

            foreach ($group as $asset) {
                $this->log($asset, $requester, $lessor, trans('admin/deployments/general.pickup_log_requested', ['id' => $pickup->id]));
            }
            $pickups->push($pickup);
        }

        return ['pickups' => $pickups, 'skipped' => $skipped];
    }

    /** Record the lessor's answer: their load number and the window they booked. */
    public function schedule(LeasePickup $pickup, ?string $loadNumber, ?string $date, ?string $window): LeasePickup
    {
        $pickup->fill([
            'load_number' => $loadNumber ?: $pickup->load_number,
            'scheduled_date' => $date ?: $pickup->scheduled_date,
            'scheduled_window' => $window ?: $pickup->scheduled_window,
        ]);
        if ($pickup->status === 'requested' && $pickup->scheduled_date) {
            $pickup->status = 'scheduled';
        }
        $pickup->save();

        return $pickup;
    }

    /**
     * The truck took them. Every device still waiting lands on the returned
     * status with the pickup date as its decommission date — the pair the
     * lease closure reads as "this unit went back".
     */
    public function pickedUp(LeasePickup $pickup, string $date, ?User $actor): LeasePickup
    {
        $returned = $this->returnedStatus();

        DB::transaction(function () use ($pickup, $date, $actor, $returned) {
            foreach ($pickup->assets()->with('lessor')->get() as $asset) {
                // A device pulled back out of the pile since the request is
                // left alone: only what is still waiting went on the truck.
                if ($asset->decommission_date || ! str_starts_with((string) $asset->status?->name, 'Processing')) {
                    continue;
                }
                if ($returned) {
                    $asset->status_id = $returned->id;
                }
                $asset->decommission_date = $date;
                $asset->save();
                $this->log($asset, $actor, $asset->lessor, trans('admin/deployments/general.pickup_log_picked_up', [
                    'id' => $pickup->id,
                    'load' => $pickup->load_number ?: '—',
                ]));
            }
            $pickup->update(['status' => 'picked_up', 'picked_up_at' => $date]);
        });

        return $pickup;
    }

    public function cancel(LeasePickup $pickup): LeasePickup
    {
        $pickup->update(['status' => 'cancelled']);

        return $pickup;
    }

    /**
     * Asset ids already riding on a pickup that has not left yet.
     *
     * @return array<int, int>
     */
    public function assetIdsOnOpenPickups(): array
    {
        return DB::table('lease_pickup_assets')
            ->join('lease_pickups', 'lease_pickups.id', '=', 'lease_pickup_assets.lease_pickup_id')
            ->whereIn('lease_pickups.status', LeasePickup::OPEN_STATUSES)
            ->whereNull('lease_pickups.deleted_at')
            ->pluck('lease_pickup_assets.lease_pickup_id', 'lease_pickup_assets.asset_id')
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** The archived status a returned device lands on, resolved by name. */
    private function returnedStatus(): ?Statuslabel
    {
        return Statuslabel::where('name', config('leasing.pickup_completed_status'))
            ->where('archived', 1)
            ->first();
    }

    private function log(Asset $asset, ?User $actor, ?Supplier $lessor, string $note): void
    {
        $log = new Actionlog;
        $log->item_type = Asset::class;
        $log->item_id = $asset->id;
        $log->setAttribute('created_by', $actor?->id);
        $log->target_id = $lessor?->id;
        $log->target_type = Supplier::class;
        $log->company_id = $asset->company_id;
        $log->note = $note;
        $log->logaction(ActionType::PickupRequested->value);
    }
}

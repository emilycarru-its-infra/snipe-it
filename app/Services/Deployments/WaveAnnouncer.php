<?php

namespace App\Services\Deployments;

use App\Mail\DeploymentWaveMail;
use App\Models\Asset;
use App\Models\DeploymentItem;
use App\Models\DeploymentWave;
use App\Models\StoreOrder;
use App\Models\User;
use App\Models\UserAgreement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Telling the people in a wave that it is happening.
 *
 * For most waves this is a courtesy. For the Faculty Laptop Program it is the
 * first real step: the annual email is what opens the cycle, and nothing —
 * choosing a model, an order, a lease schedule — happens before it goes out. So
 * sending it starts the wave rather than merely noting something.
 *
 * Recipients come from the devices, not from a mailing list. A wave's items point
 * at assets, and an asset checked out to a person names that person; one email
 * per person, carrying their own devices, however many of them are in the wave.
 * A list maintained separately would be wrong within a term.
 */
class WaveAnnouncer
{
    /**
     * One row per person: the recipient and the devices of theirs in this wave.
     *
     * @return Collection<int, array{user: User, assets: Collection<int, Asset>}>
     */
    public function recipients(DeploymentWave $wave, ?string $audience = null): Collection
    {
        // Both sides of a swap, because on a refresh wave the new machine does
        // not exist yet: the item carries only `replaces_asset_id`, and the
        // person is on the device being replaced. Reading `asset_id` alone found
        // nobody in a wave of 21 named faculty.
        $items = DeploymentItem::where('wave_id', $wave->id)
            ->get(['asset_id', 'replaces_asset_id']);

        $assetIds = $items->pluck('replaces_asset_id')
            ->merge($items->pluck('asset_id'))
            ->filter()
            ->unique()
            ->values();

        if ($assetIds->isEmpty()) {
            return collect();
        }

        $rows = collect();

        $byHolder = Asset::with('assignedTo', 'model')
            ->whereIn('id', $assetIds)
            ->where('assigned_type', User::class)
            ->whereNotNull('assigned_to')
            ->get()
            ->groupBy('assigned_to');

        foreach ($byHolder as $assets) {
            // holderUser() rather than the raw morph, so a device checked out to
            // a location or another asset cannot masquerade as a person with an
            // inbox.
            $user = $assets->first()->holderUser();

            if ($user && filled($user->email)) {
                $rows->push(['user' => $user, 'assets' => $assets->values()]);
            }
        }

        return $this->forAudience($rows->values(), $audience);
    }

    /** Who a send is aimed at. */
    public const AUDIENCES = [self::AUDIENCE_ALL, self::AUDIENCE_NO_APPLICATION, self::AUDIENCE_NO_ORDER];

    public const AUDIENCE_ALL = 'all';

    public const AUDIENCE_NO_APPLICATION = 'no_application';

    public const AUDIENCE_NO_ORDER = 'no_order';

    /**
     * Narrow a wave to the people a chasing email is actually for.
     *
     * The whole point is that nobody has to work out the list by hand. Wave 2
     * went out to everyone, and finding who had stalled meant reading the
     * agreement ledger against the store orders and comparing names — which is
     * how somebody sat stuck for five weeks without anyone noticing.
     *
     * The two questions worth asking are the two steps of the program: has this
     * person applied, and have they chosen a machine. Both are answered from
     * the same facts the rest of the system runs on, so a reminder cannot be
     * sent to somebody who already did the thing.
     *
     * @param  Collection<int, array{user: User, assets: Collection<int, Asset>}>  $rows
     * @return Collection<int, array{user: User, assets: Collection<int, Asset>}>
     */
    private function forAudience(Collection $rows, ?string $audience): Collection
    {
        if ($audience === null || $audience === self::AUDIENCE_ALL || $rows->isEmpty()) {
            return $rows;
        }

        $userIds = $rows->pluck('user.id');

        // An application is a submitted form, not a row the lease-end
        // pipeline wrote on somebody's behalf — terms_accepted_at is the
        // stamp only a real submission carries, and it is the same test the
        // store gate uses to decide whether to let them in.
        $applied = UserAgreement::whereIn('user_id', $userIds)
            ->where('agreement_type', 'pickup')
            ->whereNotNull('terms_accepted_at')
            ->whereIn('lifecycle_stage', UserAgreement::OPEN_LIFECYCLE_STAGES)
            ->pluck('user_id')
            ->unique();

        if ($audience === self::AUDIENCE_NO_APPLICATION) {
            return $rows->reject(fn ($row) => $applied->contains($row['user']->id))->values();
        }

        // Chasing an order is only fair once they can place one, so this is
        // people who applied and stopped — never people who have not started.
        $ordered = StoreOrder::whereIn('user_id', $userIds)
            ->whereNotIn('status', ['declined', 'cancelled'])
            ->pluck('user_id')
            ->unique();

        return $rows
            ->filter(fn ($row) => $applied->contains($row['user']->id)
                && ! $ordered->contains($row['user']->id))
            ->values();
    }

    /**
     * The merge values available to the subject and body, resolved per person.
     *
     * Deliberately small and about the recipient: their name, the machine they
     * hold, when its lease ends, and the link they are being sent to. Anything
     * the sender wants to say that is the same for everybody they can simply
     * type — a merge field is only worth having when the value differs.
     *
     * @param  Collection<int, Asset>  $assets
     * @return array<string, mixed>
     */
    public function context(DeploymentWave $wave, User $user, Collection $assets, array $extra = []): array
    {
        $asset = $assets->first();
        $leaseEnd = $assets->pluck('lease_end_date')->filter()->sort()->first();

        return array_merge([
            'recipient' => $user->present()->fullName,
            'first_name' => $user->first_name,
            'email' => $user->email,
            'wave' => $wave->name,
            'fiscal_year' => (string) $wave->fiscal_year,
            // The plain year, because a subject line says "2026", not
            // "FY2026-27". Taken from when the wave deploys if that is known,
            // since a wave opened in December is next year's programme.
            'year' => ($wave->target_start_date ?? $wave->arrival_window_start ?? now())->format('Y'),
            'device' => $asset ? trim($asset->present()->name.' '.($asset->asset_tag ? '('.$asset->asset_tag.')' : '')) : '',
            'device_model' => (string) $asset?->model?->name,
            'device_count' => $assets->count(),
            'lease_end' => $leaseEnd ? \Carbon\Carbon::parse($leaseEnd)->format('F j, Y') : '',
            'lease_end_year' => $leaseEnd ? \Carbon\Carbon::parse($leaseEnd)->format('Y') : '',
            'form_url' => route('forms.show', 'faculty-program'),
            'store_url' => route('store.index'),
        ], $extra);
    }

    /**
     * Send it.
     *
     * A test goes to whoever pressed the button, rendered against the first real
     * recipient's facts — a test against invented values would not show whether
     * the merge fields resolve, which is the thing most likely to be wrong.
     *
     * A real send stamps the wave and starts it: `announced_at` records when the
     * people in it were told, and a wave still sitting in `planned` moves on,
     * because the announcement is the commitment that begins the cycle.
     *
     * @return array{sent: int, recipients: array<int, string>, failed: array<int, string>}
     */
    public function send(
        DeploymentWave $wave,
        string $subject,
        string $body,
        User $actor,
        bool $test = false,
        array $extra = [],
        ?Collection $cc = null,
        ?Collection $testRecipients = null,
        ?string $audience = null,
    ): array {
        $recipients = $this->recipients($wave, $audience);

        if ($recipients->isEmpty()) {
            return ['sent' => 0, 'recipients' => [], 'failed' => []];
        }

        $ccAddresses = collect($cc ?? [])->map(fn (User $user) => $user->email)->filter()->unique()->values()->all();

        if ($test) {
            $first = $recipients->first();
            $context = $this->context($wave, $first['user'], $first['assets'], $extra);

            // Whoever was named, or the sender when nobody was: a test is for
            // reading the thing before 21 people do, and more than one pair of
            // eyes on an annual letter is the normal case.
            $to = collect($testRecipients ?? [])->map(fn (User $user) => $user->email)->filter()->unique()->values()->all();
            $to = $to === [] ? [$actor->email] : $to;

            Mail::to($to)->send(new DeploymentWaveMail(
                $wave, $first['user'], $subject, $body, $first['assets'], $context, true
            ));

            return ['sent' => count($to), 'recipients' => $to, 'failed' => []];
        }

        $sent = [];
        $failed = [];

        foreach ($recipients as $row) {
            $context = $this->context($wave, $row['user'], $row['assets'], $extra);

            try {
                $mail = Mail::to($row['user']->email);

                if ($ccAddresses !== []) {
                    $mail->cc($ccAddresses);
                }

                $mail->send(new DeploymentWaveMail(
                    $wave, $row['user'], $subject, $body, $row['assets'], $context
                ));
                $sent[] = $row['user']->email;
            } catch (\Throwable $e) {
                // One bad address must not stop the rest of a cohort being told.
                Log::warning("Wave announcement to {$row['user']->email} failed for wave {$wave->id}: ".$e->getMessage());
                $failed[] = $row['user']->email;
            }
        }

        if ($sent !== []) {
            $wave->announced_at = $wave->announced_at ?? now();

            if ($wave->wave_state === 'planned') {
                $wave->wave_state = 'ordered';
            }

            $wave->save();
        }

        return ['sent' => count($sent), 'recipients' => $sent, 'failed' => $failed];
    }
}

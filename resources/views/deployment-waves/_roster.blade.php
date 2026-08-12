{{-- Who was invited, who has acted, and who probably should not have been asked.

     Three questions that were previously answered by reading two screens and
     comparing names, which is how a faculty member gets refreshed a year early
     and nobody notices until the lease report in March. --}}
@php
    $ineligibleIds = $announceIneligible->pluck('user.id')->all();
    $ineligibleReasons = $announceIneligible->keyBy('user.id');
    $intentByUser = $intentRows->keyBy(fn ($row) => $row['user']?->id);
    $ordered = $announceRecipients->filter(fn ($row) => isset($waveOrders[$row['user']->id]))->count();
@endphp

<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title"><i class="fas fa-users"></i> {{ trans('admin/deployments/general.roster_title') }}</h3>
        <div class="box-tools pull-right">
            <span class="label label-{{ $ordered === $announceRecipients->count() ? 'success' : 'default' }}">
                {{ trans('admin/deployments/general.roster_ordered_count', ['ordered' => $ordered, 'total' => $announceRecipients->count()]) }}
            </span>
        </div>
    </div>

    @if ($announceIneligible->isNotEmpty())
        <div class="box-body" style="padding-bottom:0;">
            <p class="text-warning">
                <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
                {{ trans_choice('admin/deployments/general.roster_ineligible_warning', $announceIneligible->count(), ['count' => $announceIneligible->count()]) }}
            </p>
        </div>
    @endif

    <div class="box-body table-responsive">
        <table class="table table-striped table-condensed">
            <thead>
                <tr>
                    <th>{{ trans('admin/deployments/general.roster_person') }}</th>
                    <th>{{ trans('admin/deployments/general.roster_device') }}</th>
                    <th>{{ trans('admin/deployments/general.roster_due') }}</th>
                    <th>{{ trans('admin/deployments/general.roster_said') }}</th>
                    <th>{{ trans('admin/deployments/general.roster_actual') }}</th>
                    <th>{{ trans('admin/deployments/general.roster_ordered') }}</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($announceRecipients as $row)
                @php
                    $user = $row['user'];
                    $asset = $row['assets']->first();
                    $order = $waveOrders[$user->id] ?? null;
                    $intent = $intentByUser[$user->id] ?? null;
                    $flagged = in_array($user->id, $ineligibleIds, true);
                @endphp
                <tr @class(['warning' => $flagged])>
                    <td>
                        <a href="{{ route('users.show', $user) }}">{{ $user->present()->fullName }}</a>
                        @if ($flagged)
                            <i class="fas fa-triangle-exclamation text-warning" aria-hidden="true"
                               title="{{ trans('admin/deployments/general.roster_reason_'.$ineligibleReasons[$user->id]['reason']) }}"></i>
                        @endif
                    </td>
                    <td>
                        @if ($asset)
                            <a href="{{ route('hardware.show', $asset) }}">{{ $asset->asset_tag ?: $asset->present()->name }}</a>
                        @else
                            <span class="text-muted">&mdash;</span>
                        @endif
                    </td>
                    @php $due = $asset ? (new \App\Services\Deployments\WaveMembership)->dueDate($asset) : null; @endphp
                    <td>
                        {{ $due?->toDateString() ?? '—' }}
                        @if ($due && $asset->asset_eol_date && $due->isSameDay(\Carbon\Carbon::parse($asset->asset_eol_date)))
                            <span class="text-muted">{{ trans('admin/deployments/general.roster_due_eol') }}</span>
                        @elseif ($due)
                            <span class="text-muted">{{ trans('admin/deployments/general.roster_due_lease') }}</span>
                        @endif
                    </td>
                    <td>
                        @if ($intent)
                            {{ trans('admin/user-agreements/general.intent_'.$intent['intent']) }}
                        @else
                            <span class="text-muted">{{ trans('admin/deployments/general.roster_no_answer') }}</span>
                        @endif
                    </td>
                    <td>
                        @if ($intent)
                            <span class="{{ $intent['matches'] ? '' : 'text-danger' }}">
                                {{ trans('admin/user-agreements/general.intent_actual_'.$intent['actual']) }}
                            </span>
                        @else
                            <span class="text-muted">&mdash;</span>
                        @endif
                    </td>
                    <td>
                        @if ($order)
                            <a href="{{ route('procurement.queue', ['status' => $order->status]) }}">{{ $order->reference() }}</a>
                            <span class="label label-default">{{ $order->displayStatus() }}</span>
                        @else
                            <span class="text-muted">{{ trans('admin/deployments/general.roster_not_ordered') }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted">{{ trans('admin/deployments/general.announce_no_recipients') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

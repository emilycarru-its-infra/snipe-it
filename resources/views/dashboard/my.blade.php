@extends('layouts/default')

@section('title')
    {{ trans('general.viewassets') }}
    @parent
@stop

{{-- /my — the end-user one-stop, at a link short enough to say out loud.

     One screen, no tabs. The journey tracker names its order and machine so
     nobody has to guess what the chevrons are about; the lease answer lives
     on the asset rows themselves rather than a separate card, because most
     of the fleet is leased and the card was just the first row repeated.
     Machines sort above peripherals, accessories sink to the bottom, and
     each leased row carries its own "request a buyout" doorway. No page
     header, no avatar: the greeting is the header. --}}

@section('content')

<style>
/* The layout's page-title strip would say the obvious over its own greeting. */
.content-header { display: none; }
/* Layout only — the component skins (cards, kickers, chevrons, tags, quiet
   tables) come from the shared ECU design layer (public/css/ecu-ui.css). */
/* Centred, and sized like a product rather than a draft pinned to the
   left edge of a wide screen. */
.eud-wrap { max-width: 1200px; margin: 0 auto; }
.eud-cols { display: flex; gap: 20px; flex-wrap: wrap; align-items: flex-start; }
.eud-main { flex: 1 1 680px; min-width: 0; }
.eud-side { flex: 1 1 300px; max-width: 360px; }
.eud-hello { font-size: 28px; font-weight: 700; margin: 18px 0 4px; }
.eud-sub { opacity: .65; margin: 0 0 22px; }
.eud-card { padding: 20px 24px; margin-bottom: 16px; }
.eud-wrap .ecu-kicker { margin-bottom: 10px; }
.eud-order-line { font-size: 15px; margin: 0 0 10px; }
.eud-wrap .ecu-rail { margin: 6px 0 4px; }
/* The equipment list is the page — read at body size, not caption size. */
.eud-wrap .ecu-table { font-size: 14px; }
.eud-wrap .ecu-table td { padding: 10px 12px 10px 0; }
.eud-wrap .ecu-table img { max-height: 40px; max-width: 56px; object-fit: contain; }
.eud-lease-date { font-weight: 700; white-space: nowrap; }
.eud-lease-sub { font-size: 11px; opacity: .6; white-space: nowrap; }
.eud-refresh summary { list-style: none; cursor: pointer; display: inline-block; }
.eud-refresh summary::-webkit-details-marker { display: none; }
.eud-refresh-form { margin-top: 6px; min-width: 200px; }
.eud-profile td { padding: 5px 10px 5px 0; font-size: 13.5px; }
.eud-profile td:first-child { opacity: .6; white-space: nowrap; }
</style>

<div class="eud-wrap">

    <p class="eud-hello">{{ trans('admin/store/general.dash_hello', ['name' => $user->first_name]) }}</p>
    <p class="eud-sub">{{ trans('admin/store/general.dash_sub') }}</p>

    <div class="eud-cols">
        <div class="eud-main">

            {{-- The journey, while one is under way — named, so it is clear
                 which order and which machine the chevrons are about. --}}
            @if ($steps)
                <div class="ecu-card eud-card">
                    <div class="ecu-kicker">
                        {{ trans('admin/store/general.dash_journey') }}
                        @if ($order) · {{ $order->reference() }} @endif
                    </div>
                    @if ($orderSummary || $incoming)
                        <p class="eud-order-line">
                            @if ($orderSummary)<strong>{{ $orderSummary }}</strong>@endif
                            @if ($incoming) <span class="ecu-tag" style="margin-left:6px;">{{ $incoming->asset_tag }}</span> @endif
                        </p>
                    @endif
                    <div class="ecu-rail">
                        @foreach ($steps as $step)
                            <div class="ecu-chev {{ $step['state'] }}">{{ trans('admin/store/general.dash_step_'.$step['key']) }}</div>
                        @endforeach
                    </div>
                    @if ($journeyComplete)
                        <p style="margin:10px 0 0;"><strong>{{ trans('admin/store/general.dash_complete') }}</strong></p>
                    @elseif ($order)
                        <p class="eud-sub" style="margin:10px 0 0;">
                            {{ trans('admin/store/general.dash_journey_sub') }}
                            <a href="{{ route('store.orders') }}">{{ trans('admin/store/general.my_orders') }}</a>
                        </p>
                    @endif
                </div>
            @endif

            {{-- Renewal season, before anything has been started. --}}
            @if ($renewalDue)
                <div class="ecu-card eud-card" style="border-color:#f39c12;">
                    <div class="ecu-kicker">{{ trans('admin/store/general.dash_renewal_kicker') }}</div>
                    <p style="margin:0 0 12px;">{{ trans('admin/store/general.dash_renewal_body', ['date' => $leaseEnd->format('F j, Y')]) }}</p>
                    <a href="{{ route('forms.show', 'faculty-program') }}" class="btn btn-warning btn-lg">
                        {{ trans('admin/store/general.dash_renewal_button') }}
                        <i class="fas fa-arrow-right" aria-hidden="true"></i>
                    </a>
                </div>
            @endif

            {{-- Everything checked out to them, lease facts on the rows —
                 the date leads, the countdown is the small print — and an
                 Actions column carrying each machine's doorway: buyout for
                 Faculty program machines, early refresh for Staff ones. --}}
            <div class="ecu-card eud-card">
                <div class="ecu-kicker">{{ trans('general.assets') }} <span class="badge">{{ $myAssets->count() }}</span></div>
                @if ($myAssets->isEmpty())
                    <p class="eud-sub" style="margin:0;">{{ trans('admin/store/general.dash_no_laptop') }}</p>
                @else
                    <table class="ecu-table">
                        <thead><tr>
                            <th></th>
                            <th>{{ trans('mail.asset_tag') }}</th>
                            <th>{{ trans('general.asset_model') }}</th>
                            <th>{{ trans('mail.serial') }}</th>
                            <th>{{ trans('general.category') }}</th>
                            <th>{{ trans('admin/store/general.dash_lease_col') }}</th>
                            <th>{{ trans('admin/store/general.dash_actions_col') }}</th>
                        </tr></thead>
                        <tbody>
                        @foreach ($myAssets as $asset)
                            @php
                                $leaseEnds = $asset->leaseEndDate();
                                $leaseActive = $leaseEnds && $leaseEnds->gte(today());
                            @endphp
                            <tr>
                                <td>@if ($asset->getImageUrl())<img src="{{ $asset->getImageUrl() }}" alt="">@endif</td>
                                <td class="ecu-tag">{{ $asset->asset_tag }}</td>
                                <td>{{ $asset->model?->name }}</td>
                                <td class="ecu-tag">{{ $asset->serial }}</td>
                                <td>{{ $asset->model?->category?->name }}</td>
                                <td>
                                    @if ($leaseActive)
                                        <div class="eud-lease-date">{{ $leaseEnds->format('M j, Y') }}</div>
                                        <div class="eud-lease-sub">{{ trans('admin/store/general.dash_lease_days_small', ['days' => number_format(max((int) now()->diffInDays($leaseEnds, false), 0))]) }}</div>
                                        @if (is_numeric($asset->buyout_cost) && $asset->isFacultyCatalog())
                                            <div class="eud-lease-sub">{{ trans('admin/store/general.dash_buyout_estimate', ['cost' => \App\Helpers\Helper::formatCurrencyOutput($asset->buyout_cost)]) }}</div>
                                        @endif
                                    @elseif ($leaseEnds)
                                        <div class="eud-lease-sub">{{ trans('admin/store/general.dash_lease_ended', ['date' => $leaseEnds->format('M j, Y')]) }}</div>
                                    @else
                                        <span style="opacity:.35;">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($asset->isFacultyCatalog() && $leaseActive)
                                        @if ($requestedAt = $buyoutRequestedAt->get($asset->id))
                                            <div class="eud-lease-sub">{{ trans('admin/store/general.dash_buyout_requested', ['date' => $requestedAt->format('M j')]) }}</div>
                                        @elseif ($asset->canRequestLeaseBuyout())
                                            <form method="POST" action="{{ route('my.request-buyout', $asset->id) }}">
                                                {{ csrf_field() }}
                                                <button type="submit" class="btn btn-xs btn-default">{{ trans('admin/store/general.dash_buyout_button') }}</button>
                                            </form>
                                        @endif
                                    @elseif ($asset->isStaffCatalog())
                                        @if ($requestedAt = $refreshRequestedAt->get($asset->id))
                                            <div class="eud-lease-sub">{{ trans('admin/store/general.dash_refresh_requested', ['date' => $requestedAt->format('M j')]) }}</div>
                                        @else
                                            <details class="eud-refresh">
                                                <summary class="btn btn-xs btn-default">{{ trans('admin/store/general.dash_refresh_button') }}</summary>
                                                <form method="POST" action="{{ route('my.request-early-refresh', $asset->id) }}" class="eud-refresh-form">
                                                    {{ csrf_field() }}
                                                    <textarea name="note" rows="2" class="form-control input-sm" maxlength="1000"
                                                              placeholder="{{ trans('admin/store/general.dash_refresh_note_placeholder') }}"></textarea>
                                                    <button type="submit" class="btn btn-xs btn-primary" style="margin-top:4px;">{{ trans('admin/store/general.dash_refresh_send') }}</button>
                                                </form>
                                            </details>
                                        @endif
                                    @else
                                        <span style="opacity:.35;">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            @foreach ([
                'general.licenses' => $myLicenses,
                'general.accessories' => $myAccessories,
                'general.consumables' => $myConsumables,
            ] as $labelKey => $rows)
                @if ($rows->isNotEmpty())
                    <div class="ecu-card eud-card">
                        <div class="ecu-kicker">{{ trans($labelKey) }} <span class="badge">{{ $rows->count() }}</span></div>
                        <table class="ecu-table">
                            <tbody>
                            @foreach ($rows as $row)
                                <tr><td>{{ $row->name }}</td></tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endforeach

        </div>

        {{-- The profile, as a quiet column — they know who they are, but IT
             will ask "what does it say your location is". No avatar. --}}
        <div class="eud-side">
            <div class="ecu-card eud-card">
                <div class="ecu-kicker">{{ trans('general.profile') }}</div>
                <table class="eud-profile">
                    <tr><td>{{ trans('general.name') }}</td><td>{{ $user->present()->fullName }}</td></tr>
                    @if ($user->jobtitle)<tr><td>{{ trans('admin/users/table.title') }}</td><td>{{ $user->jobtitle }}</td></tr>@endif
                    @if ($user->employee_num)<tr><td>{{ trans('admin/users/table.employee_num') }}</td><td>{{ $user->employee_num }}</td></tr>@endif
                    <tr><td>{{ trans('admin/users/table.email') }}</td><td>{{ $user->email }}</td></tr>
                    @if ($user->department)<tr><td>{{ trans('general.department') }}</td><td>{{ $user->department->name }}</td></tr>@endif
                    @if ($user->userloc)<tr><td>{{ trans('general.location') }}</td><td>{{ $user->userloc->name }}</td></tr>@endif
                </table>
                {{-- This page is the front door now, but licences, accepted
                     EULAs and consumables still live on the old tabbed
                     profile. One link out rather than six cards nobody
                     opens; the tabs fold in when that page is redesigned. --}}
                <p style="margin:10px 0 0;">
                    <a href="{{ route('view-assets') }}">{{ trans('admin/store/general.dash_full_profile') }}</a>
                </p>
            </div>

            <div class="ecu-card eud-card">
                <div class="ecu-kicker">{{ trans('admin/store/general.dash_go') }}</div>
                @if (! empty($formsAccessible))
                    <p style="margin:0 0 8px;"><a href="{{ route('forms.index') }}"><i class="fas fa-file-signature fa-fw"></i> {{ trans('admin/forms/general.menu_link') }}</a></p>
                @endif
                @if ($user->canUseStore())
                    <p style="margin:0 0 8px;"><a href="{{ route('store.index') }}"><i class="fa-solid fa-store fa-fw"></i> {{ trans('admin/store/general.store') }}</a></p>
                    <p style="margin:0;"><a href="{{ route('store.orders') }}"><i class="fa-solid fa-truck-fast fa-fw"></i> {{ trans('admin/store/general.my_orders') }}</a></p>
                @endif
            </div>
        </div>
    </div>

</div>

@stop

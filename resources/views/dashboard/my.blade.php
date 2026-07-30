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
.eud-wrap { max-width: 1100px; }
.eud-cols { display: flex; gap: 18px; flex-wrap: wrap; align-items: flex-start; }
.eud-main { flex: 1 1 620px; min-width: 0; }
.eud-side { flex: 0 1 280px; }
.eud-hello { font-size: 24px; font-weight: 700; margin: 14px 0 2px; }
.eud-sub { opacity: .65; margin: 0 0 18px; }
.eud-card { border: 1px solid light-dark(#e2e2e6, #3a3a3e); border-radius: 14px;
    background: light-dark(#fff, #1f2023); padding: 18px 20px; margin-bottom: 14px; }
.eud-kicker { font-size: 12px; letter-spacing: .08em; text-transform: uppercase; opacity: .55; margin-bottom: 8px; }
.eud-order-line { font-size: 15px; margin: 0 0 10px; }
.eud-rail { display: flex; gap: 4px; margin: 6px 0 4px; }
.eud-chev { flex: 1; text-align: center; font-size: 12px; font-weight: 600; padding: 12px 6px 12px 14px;
    background: light-dark(#e9e9ee, #33343a); color: light-dark(#5a5a62, #9a9aa4);
    clip-path: polygon(0 0, calc(100% - 14px) 0, 100% 50%, calc(100% - 14px) 100%, 0 100%, 14px 50%); }
.eud-chev:first-child { clip-path: polygon(0 0, calc(100% - 14px) 0, 100% 50%, calc(100% - 14px) 100%, 0 100%); }
.eud-chev:last-child { clip-path: polygon(0 0, 100% 0, 100% 100%, 0 100%, 14px 50%); }
.eud-chev.done { background: #00a65a; color: #fff; }
.eud-chev.now { background: light-dark(#f39c12, #d68910); color: #fff; }
.eud-tag { font-family: ui-monospace, Menlo, monospace; font-weight: 700; }
.eud-table { width: 100%; font-size: 13px; }
.eud-table th { opacity: .55; font-weight: 600; font-size: 11px; text-transform: uppercase; text-align: left; padding: 4px 10px 4px 0; }
.eud-table td { padding: 6px 10px 6px 0; border-top: 1px solid light-dark(#f0f0f3, #2c2d31); vertical-align: middle; }
.eud-table img { max-height: 30px; max-width: 44px; object-fit: contain; }
.eud-lease-date { font-weight: 700; white-space: nowrap; }
.eud-lease-sub { font-size: 11px; opacity: .6; white-space: nowrap; }
.eud-buyout-btn { margin-top: 3px; }
.eud-profile td { padding: 4px 8px 4px 0; font-size: 13px; }
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
                <div class="eud-card">
                    <div class="eud-kicker">
                        {{ trans('admin/store/general.dash_journey') }}
                        @if ($order) · {{ $order->reference() }} @endif
                    </div>
                    @if ($orderSummary || $incoming)
                        <p class="eud-order-line">
                            @if ($orderSummary)<strong>{{ $orderSummary }}</strong>@endif
                            @if ($incoming) <span class="eud-tag" style="margin-left:6px;">{{ $incoming->asset_tag }}</span> @endif
                        </p>
                    @endif
                    <div class="eud-rail">
                        @foreach ($steps as $step)
                            <div class="eud-chev {{ $step['state'] }}">{{ trans('admin/store/general.dash_step_'.$step['key']) }}</div>
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
                <div class="eud-card" style="border-color:#f39c12;">
                    <div class="eud-kicker">{{ trans('admin/store/general.dash_renewal_kicker') }}</div>
                    <p style="margin:0 0 12px;">{{ trans('admin/store/general.dash_renewal_body', ['date' => $leaseEnd->format('F j, Y')]) }}</p>
                    <a href="{{ route('forms.show', 'faculty-program') }}" class="btn btn-warning btn-lg">
                        {{ trans('admin/store/general.dash_renewal_button') }}
                        <i class="fas fa-arrow-right" aria-hidden="true"></i>
                    </a>
                </div>
            @endif

            {{-- Everything checked out to them, lease facts on the rows:
                 every leased device shows its end date (the date leads, the
                 countdown is the small print) and its buyout doorway. --}}
            <div class="eud-card">
                <div class="eud-kicker">{{ trans('general.assets') }} <span class="badge">{{ $myAssets->count() }}</span></div>
                @if ($myAssets->isEmpty())
                    <p class="eud-sub" style="margin:0;">{{ trans('admin/store/general.dash_no_laptop') }}</p>
                @else
                    <table class="eud-table">
                        <thead><tr>
                            <th></th>
                            <th>{{ trans('mail.asset_tag') }}</th>
                            <th>{{ trans('general.asset_model') }}</th>
                            <th>{{ trans('mail.serial') }}</th>
                            <th>{{ trans('general.category') }}</th>
                            <th>{{ trans('admin/store/general.dash_lease_col') }}</th>
                        </tr></thead>
                        <tbody>
                        @foreach ($myAssets as $asset)
                            <tr>
                                <td>@if ($asset->getImageUrl())<img src="{{ $asset->getImageUrl() }}" alt="">@endif</td>
                                <td class="eud-tag">{{ $asset->asset_tag }}</td>
                                <td>{{ $asset->model?->name }}</td>
                                <td class="eud-tag">{{ $asset->serial }}</td>
                                <td>{{ $asset->model?->category?->name }}</td>
                                <td>
                                    @php
                                        $leaseEnds = $asset->leaseEndDate();
                                    @endphp
                                    @if ($leaseEnds && $leaseEnds->gte(today()))
                                        <div class="eud-lease-date">{{ $leaseEnds->format('M j, Y') }}</div>
                                        <div class="eud-lease-sub">{{ trans('admin/store/general.dash_lease_days_small', ['days' => number_format(max((int) now()->diffInDays($leaseEnds, false), 0))]) }}</div>
                                        @if (is_numeric($asset->buyout_cost))
                                            <div class="eud-lease-sub">{{ trans('admin/store/general.dash_buyout_estimate', ['cost' => \App\Helpers\Helper::formatCurrencyOutput($asset->buyout_cost)]) }}</div>
                                        @endif
                                        @if ($requestedAt = $buyoutRequestedAt->get($asset->id))
                                            <div class="eud-lease-sub">{{ trans('admin/store/general.dash_buyout_requested', ['date' => $requestedAt->format('M j')]) }}</div>
                                        @elseif ($asset->canRequestLeaseBuyout())
                                            <form method="POST" action="{{ route('my.request-buyout', $asset->id) }}" class="eud-buyout-btn">
                                                {{ csrf_field() }}
                                                <button type="submit" class="btn btn-xs btn-default">{{ trans('admin/store/general.dash_buyout_button') }}</button>
                                            </form>
                                        @endif
                                    @elseif ($leaseEnds)
                                        <div class="eud-lease-sub">{{ trans('admin/store/general.dash_lease_ended', ['date' => $leaseEnds->format('M j, Y')]) }}</div>
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
                    <div class="eud-card">
                        <div class="eud-kicker">{{ trans($labelKey) }} <span class="badge">{{ $rows->count() }}</span></div>
                        <table class="eud-table">
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
            <div class="eud-card">
                <div class="eud-kicker">{{ trans('general.profile') }}</div>
                <table class="eud-profile">
                    <tr><td>{{ trans('general.name') }}</td><td>{{ $user->present()->fullName }}</td></tr>
                    @if ($user->jobtitle)<tr><td>{{ trans('admin/users/table.title') }}</td><td>{{ $user->jobtitle }}</td></tr>@endif
                    @if ($user->employee_num)<tr><td>{{ trans('admin/users/table.employee_num') }}</td><td>{{ $user->employee_num }}</td></tr>@endif
                    <tr><td>{{ trans('admin/users/table.email') }}</td><td>{{ $user->email }}</td></tr>
                    @if ($user->department)<tr><td>{{ trans('general.department') }}</td><td>{{ $user->department->name }}</td></tr>@endif
                    @if ($user->userloc)<tr><td>{{ trans('general.location') }}</td><td>{{ $user->userloc->name }}</td></tr>@endif
                </table>
            </div>

            <div class="eud-card">
                <div class="eud-kicker">{{ trans('admin/store/general.dash_go') }}</div>
                <p style="margin:0 0 8px;"><a href="{{ route('store.index') }}"><i class="fa-solid fa-store fa-fw"></i> {{ trans('admin/store/general.store') }}</a></p>
                <p style="margin:0 0 8px;"><a href="{{ route('store.orders') }}"><i class="fa-solid fa-truck-fast fa-fw"></i> {{ trans('admin/store/general.my_orders') }}</a></p>
                @if (! empty($formsAccessible))
                    <p style="margin:0;"><a href="{{ route('forms.index') }}"><i class="fas fa-file-signature fa-fw"></i> {{ trans('admin/forms/general.menu_link') }}</a></p>
                @endif
            </div>
        </div>
    </div>

</div>

@stop

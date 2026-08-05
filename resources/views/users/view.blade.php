@extends('layouts/default')

{{-- Page title --}}
@section('title')
{{ trans('admin/users/general.view_user', ['name' => $user->display_name]) }}
@parent
@stop

@section('header_right')
    {{-- View-as. Superuser-only and never offered for another superuser or a
         deactivated account, so the button is absent rather than present and
         refusing. --}}
@endsection

{{-- Page content.

     One page, no tabs: a user has two or three assets and an agreement or
     two, so the identity facts live in the left rail and everything the
     user holds stacks in the main column — assets first, history last.
     Sections with nothing in them do not render at all. --}}
@section('content')

    @php
        $canSeeAgreements = \App\Services\FormAccess::canViewSubmission(auth()->user(), $user->id);
        $userAgreements = $canSeeAgreements
            ? \App\Models\UserAgreement::with('asset')->where('user_id', $user->id)->orderByDesc('created_at')->get()
            : collect();
        $assetCount = $user->assets()->AssetsForShow()->count();
        $licenseCount = $user->licenses()->count();
        $accessoryCount = $user->accessories()->count();
        $consumableCount = $user->consumables()->count();
        $fileCount = $user->uploads()->count();
        $eulaCount = $user->eulas()->count();
        $managedUserCount = $user->managesUsers()->count();
        $managedLocationCount = $user->managedLocations()->count();
        $userCost = $user->getUserTotalCost();
    @endphp

    <div class="row">

        @if ($user->deleted_at!='')
            <div class="col-md-12">
                <div class="callout callout-warning">
                    <x-icon type="warning"/>
                    {{ trans('admin/users/message.user_deleted_warning') }}
                </div>
            </div>
        @endif

        {{-- ─────────────── Left rail: who this is ─────────────── --}}
        <div class="col-md-3">
            <div class="box user-side-card">
                <div class="box-body">

                    <div class="usc-actions hidden-print">
                        <x-button.edit :item="$user" :route="route('users.edit', $user->id)"/>
                        <x-button.clone :item="$user" :route="route('users.clone.show', $user)"/>
                        <x-button.restore :item="$user" :route="route('users.restore.store',  $user)"/>

                        @if($user->allAssignedCount() != '0')
                        <a href="{{ route('users.print', $user->id) }}" class="btn btn-sm btn-theme hidden-print" target="_blank" rel="noopener" data-tooltip="true" data-title="{{ trans('admin/users/general.print_assigned') }}">
                            <x-icon type="print" class="fa-fw"/>
                        </a>
                        @endif

                        @if(!empty($user->email) && ($user->allAssignedCount() != '0'))
                            <form class="form-inline" style="display: inline" action="{{ route('users.email',['userId'=> $user->id]) }}" method="POST">
                                {{ csrf_field() }}
                                <button class="btn btn-sm btn-theme hidden-print" rel="noopener" data-tooltip="true" data-title="{{ trans('admin/users/general.email_assigned') }}">
                                    <x-icon type="email" class="fa-fw"/>
                                </button>
                            </form>
                        @endif

                        @if (!empty($user->email) && ($user->activated=='1'))
                            <form action="{{ route('users.password',['userId'=> $user->id]) }}" method="POST" class="form-inline" style="display: inline">
                                {{ csrf_field() }}
                                <button class="btn btn-sm btn-theme hidden-print" data-tooltip="true" data-title="{{ trans('button.send_password_link') }}">
                                    <x-icon type="password" class="fa-fw"/>
                                </button>
                            </form>
                        @endif

                        <x-button.delete :item="$user"/>

                        @can('delete', $user)
                            <form action="{{ route('users/bulkedit') }}" method="POST" class="form-inline" style="display: inline;">
                                <input type="hidden" name="_token" value="{{ csrf_token() }}"/>
                                <input type="hidden" name="bulk_actions" value="delete"/>
                                <input type="hidden" name="ids[{{ $user->id }}]" value="{{ $user->id }}"/>
                                <button class="btn btn-sm btn-danger hidden-print" data-tooltip="true" data-title="{{ trans('tooltips.checkin_all.user') }}">
                                    <x-icon type="checkin-and-delete" class="fa-fw"/>
                                </button>
                            </form>
                        @endcan

                        {{-- View-as. Superuser-only and never offered for another
                             superuser or a deactivated account, so the button is
                             absent rather than present and refusing. Full-text,
                             warning-yellow: this one changes who you are. --}}
                        @if (auth()->user()?->isSuperUser()
                            && ! $user->isSuperUser()
                            && $user->id !== auth()->id()
                            && $user->activated
                            && $user->deleted_at === null
                            && ! session()->has(\App\Http\Controllers\ImpersonateController::SESSION_KEY))
                            <form method="POST" action="{{ route('users.impersonate', $user->id) }}" style="display:inline;">
                                {{ csrf_field() }}
                                <button type="submit" class="btn btn-sm btn-warning usc-impersonate hidden-print"
                                        title="{{ trans('admin/users/general.impersonate_hint') }}">
                                    <x-icon type="user"/>
                                    {{ trans('admin/users/general.impersonate') }}
                                </button>
                            </form>
                        @endif
                    </div>

                    <h2 class="usc-name">{{ $user->first_name }} {{ $user->last_name }}</h2>
                    @if ($user->jobtitle)
                        <div class="usc-jobtitle">{{ $user->jobtitle }}</div>
                    @endif

                    <div class="usc-rows">

                        <div class="usc-row">
                            <div class="usc-label">{{ trans('general.username') }}</div>
                            <div class="usc-value">
                                @if ($user->isSuperUser())
                                    <span class="label label-danger" data-tooltip="true" title="{{ trans('general.superuser_tooltip') }}"><x-icon type="superadmin" style="padding-right: 5px;"/>{{ $user->username }}</span>
                                @elseif ($user->hasAccess('admin'))
                                    <span class="label label-warning" data-tooltip="true" title="{{ trans('general.admin_tooltip') }}"><x-icon type="superadmin" style="padding-right: 5px;"/>{{ $user->username }}</span>
                                @else
                                    <x-copy-to-clipboard copy_what="username">{{ $user->username }}</x-copy-to-clipboard>
                                @endif
                            </div>
                        </div>

                        @if ($user->email)
                            <div class="usc-row">
                                <div class="usc-label">{{ trans('general.email') }}</div>
                                <div class="usc-value"><a href="mailto:{{ $user->email }}">{{ $user->email }}</a></div>
                            </div>
                        @endif

                        @if ($user->phone)
                            <div class="usc-row">
                                <div class="usc-label">{{ trans('general.phone') }}</div>
                                <div class="usc-value"><a href="tel:{{ $user->phone }}">{{ $user->phone }}</a></div>
                            </div>
                        @endif

                        @if ($user->employee_num)
                            <div class="usc-row">
                                <div class="usc-label">{{ trans('admin/users/table.employee_num') }}</div>
                                <div class="usc-value"><x-copy-to-clipboard copy_what="employee_num">{{ $user->employee_num }}</x-copy-to-clipboard></div>
                            </div>
                        @endif

                        @if ($user->getRawOriginal('display_name'))
                            <div class="usc-row">
                                <div class="usc-label">{{ trans('admin/users/table.display_name') }}</div>
                                <div class="usc-value">{{ $user->getRawOriginal('display_name') }}</div>
                            </div>
                        @endif

                        @if ($user->department)
                            <div class="usc-row">
                                <div class="usc-label">{{ trans('general.department') }}</div>
                                <div class="usc-value">{!! $user->department->present()->nameUrl !!}</div>
                            </div>
                        @endif

                        @if ($user->location)
                            <div class="usc-row">
                                <div class="usc-label">{{ trans('general.location') }}</div>
                                <div class="usc-value">{!! $user->location->present()->nameUrl !!}</div>
                            </div>
                        @endif

                        @if ($user->manager)
                            <div class="usc-row">
                                <div class="usc-label">{{ trans('admin/users/table.manager') }}</div>
                                <div class="usc-value">{!! $user->manager->present()->nameUrl !!}</div>
                            </div>
                        @endif

                        @if ($user->idp_url && \Illuminate\Support\Str::startsWith($user->idp_url, ['http://', 'https://']))
                            <div class="usc-row">
                                <div class="usc-label">{{ trans('admin/users/general.idp_url') }}</div>
                                <div class="usc-value">
                                    <a href="{{ $user->idp_url }}" target="_blank" rel="noopener">
                                        {{ $user->idp_label ?: trans('admin/users/general.idp_label_placeholder') }}
                                        <x-icon type="external-link" class="fa-xs"/>
                                    </a>
                                </div>
                            </div>
                        @endif

                        @if ($user->notes)
                            <div class="usc-row">
                                <div class="usc-label">{{ trans('general.notes') }}</div>
                                <div class="usc-value">{!! nl2br(Helper::parseEscapedMarkedownInline($user->notes)) !!}</div>
                            </div>
                        @endif

                        <div class="usc-row">
                            <div class="usc-label">{{ trans('general.groups') }}</div>
                            <div class="usc-value">
                                @if ($user->groups->count() > 0)
                                    @foreach ($user->groups as $group)
                                        @can('superadmin')
                                            <a href="{{ route('groups.show', $group->id) }}" class="label label-default">{{ $group->name }}</a>
                                        @else
                                            <span class="label label-default">{{ $group->name }}</span>
                                        @endcan
                                    @endforeach
                                @else
                                    <span class="text-muted"><em>{{ trans('general.no_value') }}</em></span>
                                @endif
                                @if ($user->hasIndividualPermissions())
                                    <div class="text-warning" style="margin-top: 4px;"><x-icon type="warning"/> {{ trans('admin/users/general.individual_override') }}</div>
                                @endif
                            </div>
                        </div>

                        <div class="usc-row">
                            <div class="usc-label">{{ trans('general.permissions') }}</div>
                            <div class="usc-value">
                                @if ($user->isSuperUser())
                                    <span class="label label-danger" data-tooltip="true" title="{{ trans('general.superuser_tooltip') }}">
                                        <x-icon type="superadmin" style="padding-right: 5px;"/>{{ trans('general.superuser') }}
                                    </span>
                                @elseif ($user->hasAccess('admin'))
                                    <span class="label label-warning" data-tooltip="true" title="{{ trans('general.admin_tooltip') }}">
                                        <x-icon type="superadmin" style="padding-right: 5px;"/>{{ trans('general.admin_user') }}
                                    </span>
                                @elseif (!empty($effectivePermissionsBySection))
                                    @foreach ($effectivePermissionsBySection as $section => $permissions)
                                        @foreach ($permissions as $permission)
                                            @if (($permission['status'] ?? 'allowed') === 'denied')
                                                <span class="label label-danger denied-permission" data-tooltip="true" title="{{ $permission['source_label'] }}"><x-icon type="x" class="fa-fw"/> {{ $permission['permission'] }}</span>
                                            @else
                                                <span class="label label-success" data-tooltip="true" title="{{ $permission['source_label'] }}"><x-icon type="checkmark" class="fa-fw"/> {{ $permission['permission'] }}</span>
                                            @endif
                                        @endforeach
                                    @endforeach
                                @else
                                    <span class="text-muted"><em>{{ trans('general.no_value') }}</em></span>
                                @endif
                            </div>
                        </div>

                        <div class="usc-row">
                            <div class="usc-label">{{ trans('general.last_login') }}</div>
                            <div class="usc-value">
                                @if ($user->last_login != '')
                                    {{ Helper::getFormattedDateObject($user->last_login, 'datetime', false) }}
                                    <span class="text-muted">{{ Carbon::parse($user->last_login)->diffForHumans(['parts' => 2]) }}</span>
                                @else
                                    {{ trans('general.na') }}
                                @endif
                            </div>
                        </div>

                        @if ($user->start_date != '')
                            <div class="usc-row">
                                <div class="usc-label">{{ trans('general.start_date') }}</div>
                                <div class="usc-value">{{ Helper::getFormattedDateObject($user->start_date, 'date', false) }}</div>
                            </div>
                        @endif

                        @if ($user->end_date != '')
                            <div class="usc-row">
                                <div class="usc-label">{{ trans('general.end_date') }}</div>
                                <div class="usc-value {{ ($user->end_date < Carbon::now()) ? 'text-danger' : '' }}">{{ Helper::getFormattedDateObject($user->end_date, 'date', false) }}</div>
                            </div>
                        @endif

                        <div class="usc-row">
                            <div class="usc-label">{{ trans('general.created_at') }}</div>
                            <div class="usc-value">
                                @if ($user->created_at)
                                    {{ Helper::getFormattedDateObject($user->created_at, 'datetime', false) }}
                                @else
                                    {{ trans('general.na') }}
                                @endif
                            </div>
                        </div>

                    </div>

                    {{-- Account status flags --}}
                    <div class="usc-flags">
                        @if($user->activated == '1')
                            <div><x-icon type="checkmark" class="fa-fw text-success"/> {{ trans('general.login_enabled') }}</div>
                            <div>
                                @if ($user->two_factor_active())
                                    <x-icon type="checkmark" class="fa-fw text-success"/>
                                @else
                                    <x-icon type="x" class="fa-fw text-danger"/>
                                @endif
                                {{ trans('admin/users/general.two_factor_active') }}
                            </div>
                            <div>
                                @if ($user->two_factor_active_and_enrolled())
                                    <x-icon type="checkmark" class="fa-fw text-success"/>
                                @else
                                    <x-icon type="x" class="fa-fw text-danger"/>
                                @endif
                                {{ trans('admin/users/general.two_factor_enrolled') }}
                            </div>
                        @else
                            <div><x-icon type="x" class="fa-fw text-danger"/> {{ trans('general.login_enabled') }}</div>
                        @endif

                        @if($user->vip == '1')
                            <div><x-icon type="vip" class="fa-fw text-warning"/> {{ trans('admin/users/general.vip_label') }}</div>
                        @endif

                        <div><x-icon type="api-key" class="fa-fw"/> {{ $user->tokens()->count() }} API Tokens</div>

                        @if($user->remote == '1')
                            <div><x-icon type="remote" class="fa-fw"/> {{ trans('admin/users/general.remote') }}</div>
                        @endif

                        @if($user->ldap_import == '1')
                            <div><x-icon type="ldap" class="fa-fw"/> {{ trans('admin/settings/general.ldap_enabled') }}</div>
                        @endif

                        <div>
                            @if ($user->autoassign_licenses == '1')
                                <x-icon type="checkmark" class="fa-fw text-success"/>
                            @else
                                <x-icon type="x" class="fa-fw text-danger"/>
                            @endif
                            {{ trans('general.autoassign_licenses') }}
                        </div>
                    </div>

                    @if($userCost->total_user_cost > 0)
                        <div class="usc-costs">
                            <div class="usc-row">
                                <div class="usc-label">{{ trans('general.assets') }}</div>
                                <div class="usc-value">{{ Helper::formatCurrencyOutput($userCost->asset_cost) }}</div>
                            </div>
                            <div class="usc-row">
                                <div class="usc-label">{{ trans('general.licenses') }}</div>
                                <div class="usc-value">{{ Helper::formatCurrencyOutput($userCost->license_cost) }}</div>
                            </div>
                            <div class="usc-row">
                                <div class="usc-label">{{ trans('general.accessories') }}</div>
                                <div class="usc-value">{{ Helper::formatCurrencyOutput($userCost->accessory_cost) }}</div>
                            </div>
                            <div class="usc-row">
                                <div class="usc-label">{{ trans('admin/users/table.total_assets_cost') }}</div>
                                <div class="usc-value"><strong>{{ Helper::formatCurrencyOutput($userCost->total_user_cost) }}</strong></div>
                            </div>
                        </div>
                    @endif

                    @if (($user->email!='') && ($user->activated=='1') && ($user->getAssignedItemsWithPendingAcceptance()->count() > 0))
                        <div class="usc-footer-action">
                            <form action="{{ route('users.acceptance_reminder', $user) }}" method="POST">
                                {{ csrf_field() }}
                                <button class="btn btn-warning btn-sm" type="submit">
                                    {{ trans('admin/users/general.send_acceptance_reminder') }}
                                </button>
                            </form>
                        </div>
                    @endif

                    @if ( ($user->activated == '1') && (auth()->user()->isSuperUser()) && ($user->two_factor_active_and_enrolled()) && ($snipeSettings->two_factor_enabled!='0') && ($snipeSettings->two_factor_enabled!=''))
                        <div class="usc-footer-action">
                            <a class="btn btn-theme btn-sm" id="two_factor_reset">
                                {{ trans('admin/settings/general.two_factor_reset') }}
                            </a>
                            <span id="two_factor_reseticon"></span>
                            <span id="two_factor_resetresult"></span>
                            <span id="two_factor_resetstatus"></span>
                            <p class="help-block" style="line-height: 1.6; margin-top: 6px;">
                                {{ trans('admin/settings/general.two_factor_reset_help') }}
                            </p>
                        </div>
                    @endif

                </div>
            </div>
        </div>

        {{-- ─────────────── Main column: what they hold ─────────────── --}}
        <div class="col-md-9 user-main-panel">

            <div class="box user-section">
                <div class="box-body">
                    <x-tabs.pane name="assets" class="in active">
                        <x-table.assets name="assets" :route="route('api.assets.index',['assigned_to' => e($user->id), 'assigned_type' => 'App\Models\User'])"/>
                    </x-tabs.pane>
                </div>
            </div>

            @if ($canSeeAgreements && $userAgreements->count() > 0)
                <div class="box user-section">
                    <div class="box-body">
                        <x-tabs.pane name="agreements" class="in active">
                            <x-slot:table_header>
                                {{ trans('admin/forms/general.agreements_tab_label') }} <span class="badge">{{ $userAgreements->count() }}</span>
                            </x-slot:table_header>
                            @include('users._agreements-tab', ['agreements' => $userAgreements])
                        </x-tabs.pane>
                    </div>
                </div>
            @endif

            @if ($licenseCount > 0)
                <div class="box user-section">
                    <div class="box-body">
                        <x-tabs.pane name="licenses" class="in active">
                            <x-slot:table_header>
                                {{ trans('general.licenses') }} <span class="badge">{{ $licenseCount }}</span>
                            </x-slot:table_header>
                            <table
                                data-cookie-id-table="userLicenseTable"
                                data-id-table="userLicenseTable"
                                id="userLicenseTable"
                                data-buttons="licenseButtons"
                                data-side-pagination="client"
                                data-show-footer="true"
                                data-sort-name="name"
                                class="table table-striped snipe-table table-hover"
                                data-export-options='{
                        "fileName": "export-license-{{ str_slug($user->username) }}-{{ date('Y-m-d') }}",
                        "ignoreColumn": ["actions","image","change","checkbox","checkincheckout","delete","download","icon"]
                        }'>

                                <thead>
                                    <tr>
                                        <th>{{ trans('general.name') }}</th>
                                        <th>{{ trans('admin/licenses/form.license_key') }}</th>
                                        <th data-footer-formatter="sumFormatter" data-fieldname="purchase_cost">{{ trans('general.purchase_cost') }}</th>
                                        <th>{{ trans('admin/licenses/form.purchase_order') }}</th>
                                        <th>{{ trans('general.order_number') }}</th>
                                        <th class="col-md-1 hidden-print">{{ trans('general.action') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($user->licenses as $license)
                                        <tr>
                                            <td class="col-md-4">
                                                {!! $license->present()->nameUrl() !!}
                                            </td>
                                            <td class="col-md-4">
                                                @can('viewKeys', $license)
                                                    <code class="single-line"><span class="js-copy-link" data-clipboard-target=".js-copy-key-{{ $license->id }}" aria-hidden="true" data-tooltip="true" data-placement="top" title="{{ trans('general.copy_to_clipboard') }}"><span class="js-copy-key-{{ $license->id }}">{{ $license->serial }}</span></span></code>
                                                @else
                                                    ------------
                                                @endcan
                                            </td>
                                            <td class="col-md-2">
                                                {{ Helper::formatCurrencyOutput($license->purchase_cost) }}
                                            </td>
                                            <td>
                                                {{ $license->purchase_order }}
                                            </td>
                                            <td>
                                                {{ $license->order_number }}
                                            </td>
                                            <td class="hidden-print col-md-2">
                                                @can('update', $license)
                                                    <a href="{{ route('licenses.checkin', $license->pivot->id, ['backto'=>'user']) }}" class="btn bg-purple btn-sm hidden-print">{{ trans('general.checkin') }}</a>
                                                @endcan
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </x-tabs.pane>
                    </div>
                </div>
            @endif

            @if ($accessoryCount > 0)
                <div class="box user-section">
                    <div class="box-body">
                        <x-tabs.pane name="accessories" class="in active">
                            <x-slot:table_header>
                                {{ trans('general.accessories_assigned') }} <span class="badge">{{ $accessoryCount }}</span>
                            </x-slot:table_header>
                            <table
                                data-cookie-id-table="userAccessoryTable"
                                data-id-table="userAccessoryTable"
                                id="userAccessoryTable"
                                data-buttons="accessoryButtons"
                                data-side-pagination="client"
                                data-sort-name="name"
                                class="table table-striped snipe-table table-hover"
                                data-export-options='{
                        "fileName": "export-accessory-{{ str_slug($user->username) }}-{{ date('Y-m-d') }}",
                        "ignoreColumn": ["actions","image","change","checkbox","checkincheckout","delete","download","icon"]
                        }'>
                                <thead>
                                    <tr>
                                        <th>{{ trans('general.id') }}</th>
                                        <th>{{ trans('general.name') }}</th>
                                        <th>{{ trans('general.date') }}</th>
                                        <th data-fieldname="note">{{ trans('general.notes') }}</th>
                                        <th data-footer-formatter="sumFormatter" data-fieldname="purchase_cost">{{ trans('general.unit_cost') }}</th>
                                        <th class="hidden-print">{{ trans('general.action') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($user->accessories as $accessory)
                                        <tr>
                                            <td>{{ $accessory->pivot->id }}</td>
                                            <td>{!! $accessory->present()->nameUrl() !!}</td>
                                            <td>{{ Helper::getFormattedDateObject($accessory->pivot->created_at, 'datetime',  false) }}</td>
                                            <td>{{ $accessory->pivot->note }}</td>
                                            <td>
                                                {!! Helper::formatCurrencyOutput($accessory->purchase_cost) !!}
                                            </td>
                                            <td class="hidden-print">
                                                @can('checkin', $accessory)
                                                    <a href="{{ route('accessories.checkin.show', array('accessoryID'=> $accessory->pivot->id, 'backto'=>'user')) }}" class="btn bg-purple btn-sm hidden-print">{{ trans('general.checkin') }}</a>
                                                @endcan
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </x-tabs.pane>
                    </div>
                </div>
            @endif

            @if ($consumableCount > 0)
                <div class="box user-section">
                    <div class="box-body">
                        <x-tabs.pane name="consumables" class="in active">
                            <x-slot:table_header>
                                {{ trans('general.consumables') }} <span class="badge">{{ $consumableCount }}</span>
                            </x-slot:table_header>
                            <table
                                data-cookie-id-table="userConsumableTable"
                                data-id-table="userConsumableTable"
                                id="userConsumableTable"
                                data-buttons="consumableButtons"
                                data-side-pagination="client"
                                data-show-footer="true"
                                data-sort-name="name"
                                class="table table-striped snipe-table table-hover"
                                data-export-options='{
                        "fileName": "export-consumable-{{ str_slug($user->username) }}-{{ date('Y-m-d') }}",
                        "ignoreColumn": ["actions","image","change","checkbox","checkincheckout","delete","download","icon"]
                        }'>
                                <thead>
                                    <tr>
                                        <th class="col-md-3">{{ trans('general.name') }}</th>
                                        <th class="col-md-2" data-footer-formatter="sumFormatter" data-fieldname="purchase_cost">{{ trans('general.unit_cost') }}</th>
                                        <th class="col-md-2">{{ trans('general.date') }}</th>
                                        <th class="col-md-5">{{ trans('general.notes') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($user->consumables as $consumable)
                                        <tr>
                                            <td>{!! $consumable->present()->nameUrl() !!}</td>
                                            <td>
                                                {!! Helper::formatCurrencyOutput($consumable->purchase_cost) !!}
                                            </td>
                                            <td>{{ Helper::getFormattedDateObject($consumable->pivot->created_at, 'datetime',  false) }}</td>
                                            <td>{{ $consumable->pivot->note }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </x-tabs.pane>
                    </div>
                </div>
            @endif

            @if ($eulaCount > 0)
                <div class="box user-section">
                    <div class="box-body">
                        <x-tabs.pane name="eulas" class="in active">
                            <x-slot:table_header>
                                {{ trans('general.eula') }} <span class="badge">{{ $eulaCount }}</span>
                            </x-slot:table_header>
                            <table
                                data-toolbar="#userEULAToolbar"
                                data-cookie-id-table="userEULATable"
                                data-id-table="userEULATable"
                                id="userEULATable"
                                data-side-pagination="client"
                                data-show-footer="true"
                                data-show-refresh="false"
                                data-sort-order="asc"
                                data-sort-name="name"
                                class="table table-striped snipe-table table-hover"
                                data-url="{{ route('api.user.eulas', $user) }}"
                                data-export-options='{
                        "fileName": "export-eula-{{ str_slug($user->username) }}-{{ date('Y-m-d') }}",
                        "ignoreColumn": ["actions","image","change","checkbox","checkincheckout","delete","purchasecost", "icon"]
                        }'>
                                <thead>
                                    <tr>
                                        <th data-visible="true" data-field="icon" style="width: 40px;" class="hidden-xs" data-formatter="iconFormatter">{{ trans('admin/hardware/table.icon') }}</th>
                                        <th data-visible="true" data-field="item.name">{{ trans('general.item') }}</th>
                                        <th data-visible="true" data-field="created_at" data-sortable="true" data-formatter="dateDisplayFormatter">{{ trans('general.accepted_date') }}</th>
                                        <th data-field="note">{{ trans('general.notes') }}</th>
                                        <th data-field="url" data-formatter="fileDownloadButtonsFormatter">{{ trans('general.download') }}</th>
                                    </tr>
                                </thead>
                            </table>
                        </x-tabs.pane>
                    </div>
                </div>
            @endif

            @can('files', $user)
                <div class="box user-section">
                    <div class="box-body">
                        <div class="clearfix hidden-print">
                            <button class="btn btn-sm btn-theme pull-right" data-toggle="modal" data-target="#uploadFileModal">
                                <x-icon type="paperclip" class="fa-fw"/> {{ trans('general.upload_files') }}
                            </button>
                        </div>
                        <x-tabs.pane name="files" class="in active">
                            <x-table.files object_type="users" :object="$user"/>
                        </x-tabs.pane>
                    </div>
                </div>
            @endcan

            @if ($managedUserCount > 0)
                <div class="box user-section">
                    <div class="box-body">
                        <x-tabs.pane name="managed-users" class="in active">
                            <x-slot:table_header>
                                {{ trans('admin/users/table.managed_users') }} <span class="badge">{{ $managedUserCount }}</span>
                            </x-slot:table_header>
                            @include('partials.users-bulk-actions')

                            <table
                                data-columns="{{ \App\Presenters\UserPresenter::dataTableLayout() }}"
                                data-cookie-id-table="managedUsersTable"
                                data-id-table="managedUsersTable"
                                data-toolbar="#usersBulkEditToolbar"
                                data-bulk-button-id="#bulkUserEditButton"
                                data-bulk-form-id="#usersBulkForm"
                                data-side-pagination="server"
                                id="managedUsersTable"
                                data-buttons="userButtons"
                                class="table table-striped snipe-table"
                                data-url="{{ route('api.users.index', ['manager_id' => $user->id]) }}"
                                data-export-options='{
                      "fileName": "export-users-{{ date('Y-m-d') }}",
                      "ignoreColumn": ["actions","image","change","checkbox","checkincheckout","icon"]
                      }'>
                            </table>
                        </x-tabs.pane>
                    </div>
                </div>
            @endif

            @if ($managedLocationCount > 0)
                <div class="box user-section">
                    <div class="box-body">
                        <x-tabs.pane name="locations" class="in active">
                            <x-slot:table_header>
                                {{ trans('general.locations') }} <span class="badge">{{ $managedLocationCount }}</span>
                            </x-slot:table_header>
                            <x-table
                                name="userLocations_{{ $user->id }}"
                                api_url="{{ route('api.locations.index', ['manager_id' => $user->id]) }}"
                                :presenter="\App\Presenters\LocationPresenter::dataTableLayout()"
                                export_filename="export-history-{{ str_slug($user->name) }}-{{ date('Y-m-d') }}"
                            />
                        </x-tabs.pane>
                    </div>
                </div>
            @endif

            <div class="box user-section">
                <div class="box-body">
                    <x-tabs.pane name="history" class="in active">
                        <x-table.history :model="$user" :route="route('api.users.history', $user)"/>
                    </x-tabs.pane>
                </div>
            </div>

        </div>
    </div>

@endsection

@push('css')
<style>
    {{-- Left rail rows: small uppercase label over the value, stacked —
         a dt/dd table would fight the narrow column. --}}
    .user-side-card .usc-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 4px;
        padding-bottom: 14px;
        margin-bottom: 14px;
        border-bottom: 1px solid var(--box-header-bottom-border-color);
    }
    .user-side-card .usc-actions .btn,
    .user-side-card .usc-actions .btn.btn-warning,
    .user-side-card .usc-actions .btn.btn-info,
    .user-side-card .usc-actions .btn.btn-primary,
    .user-side-card .usc-actions .btn.btn-theme {
        background: var(--box-bg) !important;
        background-image: none !important;
        border: 1px solid var(--box-header-top-border-color) !important;
        color: var(--chrome-fg-muted) !important;
        border-radius: 10px;
        float: none !important;
        margin: 0 !important;
    }
    .user-side-card .usc-actions .btn:hover {
        background: var(--chrome-hover-bg) !important;
        color: var(--chrome-fg) !important;
    }
    .user-side-card .usc-actions .btn.btn-danger {
        color: var(--text-danger) !important;
        border-color: color-mix(in srgb, var(--text-danger) 40%, transparent) !important;
    }
    .user-side-card .usc-actions .btn.btn-danger:hover {
        background: color-mix(in srgb, var(--text-danger) 12%, transparent) !important;
    }
    /* View-as keeps its warning colour inside the neutralized toolbar —
       this button changes who you are, it should not look routine. */
    .user-side-card .usc-actions .btn.usc-impersonate {
        background: #f39c12 !important;
        border-color: #f39c12 !important;
        color: #fff !important;
    }
    .user-side-card .usc-actions .btn.usc-impersonate:hover {
        background: #e08e0b !important;
        color: #fff !important;
    }
    .usc-name { font-size: 20px; font-weight: 700; margin: 0 0 2px; }
    .usc-jobtitle { color: var(--chrome-fg-muted); margin-bottom: 14px; }
    .usc-row { padding: 8px 0; border-bottom: 1px solid var(--box-header-bottom-border-color); }
    .usc-row:last-child { border-bottom: 0; }
    .usc-label {
        font-size: 11px;
        font-weight: 600;
        letter-spacing: .05em;
        text-transform: uppercase;
        color: var(--chrome-fg-muted);
        margin-bottom: 2px;
    }
    .usc-value { word-wrap: break-word; overflow-wrap: anywhere; }
    .usc-value .label { display: inline-block; margin: 1px 2px 1px 0; }
    .usc-flags {
        margin-top: 14px;
        padding-top: 12px;
        border-top: 1px solid var(--box-header-bottom-border-color);
        display: grid;
        gap: 6px;
        font-size: 13px;
    }
    .usc-costs {
        margin-top: 14px;
        padding-top: 6px;
        border-top: 1px solid var(--box-header-bottom-border-color);
    }
    .usc-footer-action { margin-top: 14px; }
    .user-main-panel .user-section { margin-bottom: 18px; }
    .user-main-panel .user-section .box-header .badge {
        background: rgba(127,127,127,0.18);
        color: inherit;
        margin-left: 6px;
    }
</style>
@endpush

@section('moar_scripts')
    @can('files', $user)
        @include ('modals.upload-file', ['item_type' => 'users', 'item_id' => $user->id])
    @endcan

    @include ('partials.bootstrap-table', ['simple_view' => true])
<script nonce="{{ csrf_token() }}">
$(function () {

  $("#two_factor_reset").click(function(){
    $("#two_factor_resetrow").removeClass('success');
    $("#two_factor_resetrow").removeClass('danger');
    $("#two_factor_resetstatus").html('');
    $("#two_factor_reseticon").html('<x-icon type="spinner" />');
    $.ajax({
      url: '{{ route('api.users.two_factor_reset', ['id'=> $user->id]) }}',
      type: 'POST',
      data: {},
      headers: {
        "X-Requested-With": 'XMLHttpRequest',
        "X-CSRF-TOKEN": $('meta[name="csrf-token"]').attr('content')
      },
      dataType: 'json',

      success: function (data) {
        $("#two_factor_reset_toggle").html('').html('<span class="text-danger"><x-icon type="x" /> {{ trans('general.no') }}</span>');
        $("#two_factor_reseticon").html('');
          $("#two_factor_resetstatus").html('<span class="text-success"><x-icon type="checkmark" /> ' + data.message + '</span>');

      },

      error: function (data) {
        $("#two_factor_reseticon").html('');
        $("#two_factor_reseticon").html('<x-icon type="warning" class="text-danger" />');
        $('#two_factor_resetstatus').text(data.message);
      }

    });
  });
});
</script>
@endsection

{{-- Desktop toolbar. The whole product hangs off six tabs: clicking a tab
     goes to that section's landing page, hovering opens its menu. Nothing
     here is a menu-only parent — every tab is a real destination.

     Below 1200px this strip hides entirely and the sidebar takes over, so
     the two navigations are never on screen at once. --}}
<ul class="nav navbar-nav topnav">

    {{-- Everyone owns their own equipment page. Users without the admin
         assets grant (department-granted readers like Finance) get an
         Assets tab pointing at /my, so gaining procurement access never
         costs them the button end users always had. --}}
    @cannot('index', \App\Models\Asset::class)
        <li style="order: {{ \App\Helpers\ToolbarConfig::order('assets') }}" class="topnav-item{!! (request()->is('my') ? ' active' : '') !!}">
            <a href="{{ route('my') }}">
                <x-icon type="assets" class="fa-fw" />
                <span class="topbar-nav-label">{{ trans('general.assets') }}</span>
            </a>
        </li>
    @endcannot
    @can('index', \App\Models\Asset::class)
        @if (\App\Helpers\ToolbarConfig::visible('assets'))
    <li style="order: {{ \App\Helpers\ToolbarConfig::order('assets') }}" class="dropdown topnav-item topnav-assets{!! (request()->is('hardware*') || request()->is('statuslabels/*') || request()->is('maintenances*') ? ' active' : '') !!}">
            <a href="{{ route('hardware.index') }}" {{ $snipeSettings->shortcuts_enabled == 1 ? 'accesskey=1' : '' }}>
                <x-icon type="assets" class="fa-fw" />
                <span class="topbar-nav-label">{{ trans('general.assets') }}</span>
            </a>
            <ul class="dropdown-menu topnav-menu">
                {{-- Fully qualified deliberately: an inline PHP block inside
                     Blade control flow cannot carry a `use` statement, because
                     PHP only permits imports at top-level file scope. A
                     formatter that hoists one here produces a parse error. --}}
                <?php $status_navs = \App\Models\Statuslabel::where('show_in_nav', '=', 1)->withCount('assets as asset_count')->get(); ?>
                @foreach ($status_navs as $status_nav)
                    <li{!! (request()->is('statuslabels/'.$status_nav->id) ? ' class="active"' : '') !!}>
                        <a href="{{ route('statuslabels.show', ['statuslabel' => $status_nav->id]) }}">
                            <i class="fas fa-circle fa-fw" aria-hidden="true"{!! ($status_nav->color != '' ? ' style="color: '.e($status_nav->color).'"' : '') !!}></i>
                            {{ $status_nav->name }}
                            <span class="badge badge-secondary">{{ $status_nav->asset_count }}</span>
                        </a>
                    </li>
                @endforeach
                @if (count($status_navs) > 0)
                    <li class="divider"></li>
                @endif
                <li{!! (request()->query('status_type') == 'Deployed' ? ' class="active"' : '') !!}>
                    <a href="{{ url('hardware?status_type=Deployed') }}">{{ trans('general.deployed') }}</a>
                </li>
                <li{!! (request()->query('status_type') == 'RTD' ? ' class="active"' : '') !!}>
                    <a href="{{ url('hardware?status_type=RTD') }}">{{ trans('general.ready_to_deploy') }}</a>
                </li>
                <li{!! (request()->query('status_type') == 'Pending' ? ' class="active"' : '') !!}>
                    <a href="{{ url('hardware?status_type=Pending') }}">{{ trans('general.pending') }}</a>
                </li>
                <li{!! (request()->query('status_type') == 'Undeployable' ? ' class="active"' : '') !!}>
                    <a href="{{ url('hardware?status_type=Undeployable') }}">{{ trans('general.undeployable') }}</a>
                </li>
                <li{!! (request()->query('status_type') == 'Requestable' ? ' class="active"' : '') !!}>
                    <a href="{{ url('hardware?status_type=Requestable') }}">{{ trans('admin/hardware/general.requestable') }}</a>
                </li>
                <li{!! (request()->query('status_type') == 'Archived' ? ' class="active"' : '') !!}>
                    <a href="{{ url('hardware?status_type=Archived') }}">{{ trans('admin/hardware/general.archived') }}</a>
                </li>
                @can('admin')
                    <li{!! (request()->query('status_type') == 'Deleted' ? ' class="active"' : '') !!}>
                        <a href="{{ url('hardware?status_type=Deleted') }}">{{ trans('general.deleted') }}</a>
                    </li>
                @endcan
            </ul>
        </li>
    @endif
    @endcan

    @can('deployments.view')
        @if (\App\Helpers\ToolbarConfig::visible('deployments'))
    <li style="order: {{ \App\Helpers\ToolbarConfig::order('deployments') }}" class="dropdown topnav-item topnav-deployments{!! ((request()->is('reports/deployments*') || request()->is('deployments*') || request()->is('deployment-waves*') || request()->is('deployment-config*') || request()->is('deployment-blackouts*')) ? ' active' : '') !!}">
            <a href="{{ route('reports.deployments') }}">
                <x-icon type="deployments" class="fa-fw" />
                <span class="topbar-nav-label">{{ trans('admin/deployments/general.dashboard_title') }}</span>
            </a>
            {{-- The button opens the board itself; the menu holds the other
                 doors. Configure is gone — the wave-type and stage catalogs
                 are managed inline on the Waves page, which itself is
                 reached from Forecast (and staffing blackouts live there
                 too), so neither needs a menu line. --}}
            <ul class="dropdown-menu topnav-menu">
                <li{!! (request()->is('deployments/planning*') ? ' class="active"' : '') !!}>
                    <a href="{{ route('deployments.planning') }}">{{ trans('admin/deployments/general.forecast') }}</a>
                </li>
                <li{!! (request()->is('deployments/storage*') ? ' class="active"' : '') !!}>
                    <a href="{{ route('deployments.storage') }}">{{ trans('admin/deployments/general.storage') }}</a>
                </li>
                <li{!! (request()->is('deployments/decommissioning*') ? ' class="active"' : '') !!}>
                    <a href="{{ route('deployments.decommissioning') }}">{{ trans('admin/deployments/general.decom_nav') }}</a>
                </li>
                @can('view', App\Models\Asset::class)
                    <li class="divider"></li>
                    <li{!! (request()->is('deployments/exhibits*') ? ' class="active"' : '') !!}>
                        <a href="{{ route('deployments.exhibits') }}">{{ trans('admin/reports/general.hub_tile_exhibit') }}</a>
                    </li>
                @endcan
            </ul>
        </li>
    @endif
    @endcan

    @if (Gate::allows('view', \App\Models\Order::class) || Gate::allows('view', \App\Models\Supplier::class) || Gate::allows('view', \App\Models\Depreciation::class) || Gate::allows('procurement.view'))
        @if (\App\Helpers\ToolbarConfig::visible('procurement'))
    <li style="order: {{ \App\Helpers\ToolbarConfig::order('procurement') }}" class="dropdown topnav-item topnav-procurement{!! (request()->is(App\Helpers\Helper::ProcurementUrls()) ? ' active' : '') !!}">
            <a href="{{ route('procurement.index') }}">
                <x-icon type="procurement" class="fa-fw" />
                <span class="topbar-nav-label">{{ trans('general.procurement') }}</span>
            </a>
            {{-- The button itself opens /procurement — the menu holds the
                 working doors, not a copy of the destination. Two runs:
                 the paperwork (orders through approvals), then the store. --}}
            {{-- Two runs: the money spine (orders → capital → leasing),
                 then the store side. Purchase orders and requisitions are
                 reachable from within Orders and Capital; Forms lives in
                 the admin gear menu now. --}}
            <ul class="dropdown-menu topnav-menu">
                @can('view', \App\Models\Order::class)
                    <li{!! (request()->is('procurement/orders*') ? ' class="active"' : '') !!}>
                        <a href="{{ route('orders.index') }}">{{ trans('admin/orders/general.orders') }}</a>
                    </li>
                    @can('procurement.view')
                        <li{!! (request()->is('procurement/capital') ? ' class="active"' : '') !!}>
                            <a href="{{ route('reports.procurement.capital-request') }}">{{ trans('admin/purchase-orders/general.capital_nav') }}</a>
                        </li>
                        <li{!! (request()->is('procurement/leasing*') ? ' class="active"' : '') !!}>
                            <a href="{{ route('reports.lessor-breakdown') }}">{{ trans('admin/purchase-orders/general.leasing_title') }}</a>
                        </li>
                    @endcan
                    <li class="divider"></li>
                @endcan
                <li{!! (request()->is('store') ? ' class="active"' : '') !!}>
                    <a href="{{ route('store.index') }}">{{ trans('admin/store/general.store') }}</a>
                </li>
                @can('admin')
                    <li{!! (request()->is('procurement/store-admin*') ? ' class="active"' : '') !!}>
                        <a href="{{ route('procurement.store-admin') }}">{{ trans('admin/store/general.store_admin') }}</a>
                    </li>
                @endcan
                @can('view', \App\Models\Order::class)
                    <li{!! (request()->is('procurement/queue*') ? ' class="active"' : '') !!}>
                        <a href="{{ route('procurement.approvals') }}">{{ trans('admin/store/general.queue') }}</a>
                    </li>
                @endcan
                @can('procurement.view')
                    <li{!! (request()->is('procurement/agreements*') ? ' class="active"' : '') !!}>
                        <a href="{{ route('reports.procurement.user-agreement-ledger') }}">{{ trans('admin/store/general.tab_agreements') }}</a>
                    </li>
                @endcan
            </ul>
        </li>
    @endif
    @endif

    @if (Gate::allows('view', \App\Models\Contract::class) || Gate::allows('view', \App\Models\License::class))
        @if (\App\Helpers\ToolbarConfig::visible('contracts'))
    <li style="order: {{ \App\Helpers\ToolbarConfig::order('contracts') }}" class="dropdown topnav-item topnav-contracts{!! ((request()->is('contracts*') || request()->is('licenses*') || request()->is('admin/license-models*')) ? ' active' : '') !!}">
            <a href="{{ route('contracts.index') }}" {{ $snipeSettings->shortcuts_enabled == 1 ? 'accesskey=2' : '' }}>
                <x-icon type="contracts" class="fa-fw" />
                <span class="topbar-nav-label">{{ trans('admin/contracts/general.contracts') }}</span>
            </a>
            {{-- The button itself opens Contracts, so the menu holds only
                 what the button does not: licenses and their types. --}}
            <ul class="dropdown-menu topnav-menu">
                @can('view', \App\Models\License::class)
                    <li{!! (request()->is('licenses*') ? ' class="active"' : '') !!}>
                        <a href="{{ route('licenses.index') }}">{{ trans('general.licenses') }}</a>
                    </li>
                @endcan
                @can('view', \App\Models\LicenseModel::class)
                    <li{!! (request()->is('admin/license-models*') ? ' class="active"' : '') !!}>
                        <a href="{{ route('license-models.index') }}">{{ trans('admin/licensemodels/general.sidebar_label') }}</a>
                    </li>
                @endcan
            </ul>
        </li>
    @endif
    @endif

    @can('view', \App\Models\Consumable::class)
        @if (\App\Helpers\ToolbarConfig::visible('consumables'))
    <li style="order: {{ \App\Helpers\ToolbarConfig::order('consumables') }}" class="dropdown topnav-item topnav-consumables{!! ((request()->is('consumables*') || request()->is('reports/transactions*') || request()->is('reports/printing*')) ? ' active' : '') !!}">
            <a href="{{ url('consumables') }}" {{ $snipeSettings->shortcuts_enabled == 1 ? 'accesskey=3' : '' }}>
                <x-icon type="consumables" class="fa-fw" />
                <span class="topbar-nav-label">{{ trans('general.consumables') }}</span>
            </a>
            {{-- The button itself opens Consumables, so the menu is a flat
                 list of the other doors — no self-referential entry, no
                 Reports section header. --}}
            <ul class="dropdown-menu topnav-menu">
                @can('reports.transactions.view')
                    <li{!! (request()->is('reports/transactions*') ? ' class="active"' : '') !!}>
                        <a href="{{ route('reports.transactions.index') }}">{{ trans('admin/reports/general.hub_tile_transactions') }}</a>
                    </li>
                    <li{!! (request()->is('reports/printing*') ? ' class="active"' : '') !!}>
                        <a href="{{ route('reports.printing') }}">{{ trans('admin/reports/general.nav_printers') }}</a>
                    </li>
                @endcan
            </ul>
        </li>
    @endif
    @endcan

    @if (Gate::allows('view', \App\Models\User::class) || Gate::allows('view', \App\Models\Department::class) || Gate::allows('view', \App\Models\Location::class) || Gate::allows('view', \App\Models\Company::class))
        @if (\App\Helpers\ToolbarConfig::visible('users'))
    <li style="order: {{ \App\Helpers\ToolbarConfig::order('users') }}" class="dropdown topnav-item topnav-users{!! ((request()->is('users*') || request()->is('groups*') || request()->is('departments*') || request()->is('locations*') || request()->is('companies*')) ? ' active' : '') !!}">
            <a href="{{ route('users.index') }}" {{ $snipeSettings->shortcuts_enabled == 1 ? 'accesskey=4' : '' }}>
                <x-icon type="users" class="fa-fw" />
                <span class="topbar-nav-label">{{ trans('general.users') }}</span>
            </a>
            <ul class="dropdown-menu topnav-menu">
                @can('view', \App\Models\User::class)
                    <li{!! (request()->is('users') && ! request()->query() ? ' class="active"' : '') !!}>
                        <a href="{{ route('users.index') }}">{{ trans('general.list_all') }}</a>
                    </li>
                    <li{!! (request()->query('superadmins') ? ' class="active"' : '') !!}>
                        <a href="{{ route('users.index', ['superadmins' => 'true']) }}">{{ trans('general.show_superadmins') }}</a>
                    </li>
                    <li{!! (request()->query('admins') ? ' class="active"' : '') !!}>
                        <a href="{{ route('users.index', ['admins' => 'true']) }}">{{ trans('general.show_admins') }}</a>
                    </li>
                    <li><a href="{{ route('users.index', ['activated' => true]) }}">{{ trans('general.login_enabled') }}</a></li>
                    <li><a href="{{ route('users.index', ['activated' => false]) }}">{{ trans('general.login_disabled') }}</a></li>
                    <li><a href="{{ route('users.index', ['status' => 'deleted']) }}">{{ trans('general.deleted_users') }}</a></li>
                    <li class="divider"></li>
                    @if (auth()->user()->isSuperUser())
                        <li{!! (request()->is('groups*') ? ' class="active"' : '') !!}>
                            <a href="{{ route('groups.index') }}">{{ trans('general.groups') }}</a>
                        </li>
                    @endif
                @endcan
                @can('view', \App\Models\Department::class)
                    <li{!! (request()->is('departments*') ? ' class="active"' : '') !!}>
                        <a href="{{ route('departments.index') }}">{{ trans('general.departments') }}</a>
                    </li>
                @endcan
                @can('view', \App\Models\Location::class)
                    <li{!! (request()->is('locations*') ? ' class="active"' : '') !!}>
                        <a href="{{ route('locations.index') }}">{{ trans('general.locations') }}</a>
                    </li>
                @endcan
                @can('view', \App\Models\Company::class)
                    <li{!! (request()->is('companies*') ? ' class="active"' : '') !!}>
                        <a href="{{ route('companies.index') }}">{{ trans('general.companies') }}</a>
                    </li>
                @endcan
            </ul>
        </li>
    @endif
    @endif

</ul>

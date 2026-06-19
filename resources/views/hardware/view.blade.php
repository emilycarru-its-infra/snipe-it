@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ trans('admin/hardware/general.view') }} {{ $asset->asset_tag }}
    @parent
@stop

@section('header_right')
    <x-button.info-panel-toggle hide-on-xs/>
@endsection

{{-- Page content --}}
@section('content')


    <x-container columns="2">

        @if (!$asset->model)
            <div class="col-md-12">
                <div class="callout callout-danger">
                    <p>
                        <strong>{{ trans('admin/models/message.no_association') }}</strong> {{ trans('admin/models/message.no_association_fix') }}
                    </p>
                </div>
            </div>
        @endif

        @if ($asset->checkInvalidNextAuditDate())
            <div class="col-md-12">
                <div class="callout callout-warning">
                    <p><strong>{{ trans('general.warning',
                        [
                            'warning' => trans('admin/hardware/message.warning_audit_date_mismatch',
                                    [
                                        'last_audit_date' => Helper::getFormattedDateObject($asset->last_audit_date, 'datetime', false),
                                        'next_audit_date' => Helper::getFormattedDateObject($asset->next_audit_date, 'date', false)
                                    ]
                                    )
                        ]
                        ) }}</strong></p>
                </div>
            </div>
        @endif

        @if ($asset->deleted_at!='')
            <div class="col-md-12">
                <div class="callout callout-warning">
                    <x-icon type="warning"/>
                    {{ trans('general.asset_deleted_warning') }}
                </div>
            </div>
        @endif

        <x-page-column class="col-md-9 col-md-push-3 main-panel">

            <x-tabs>
                <x-slot:tabnav>
                    <x-tabs.details-tab/>
                    <x-tabs.license-tab count="{{ $asset->licenses->count() }}"/>
                    <x-tabs.component-tab count="{{ $asset->components()->sum('assigned_qty') }}"/>
                    <x-tabs.asset-tab count="{{ $asset->assignedAssets()->AssetsForShow()->count() }}"/>
                    <x-tabs.accessory-tab count="{{ $asset->assignedAccessories()->count() }}"/>
                    <x-tabs.maintenance-tab count="{{ $asset->maintenances->count() }}"/>

                    @if (! empty($printerUsage))
                        <x-tabs.nav-item
                            name="printing"
                            icon_type="print"
                            label="{{ trans('admin/hardware/printing.tab_label') }}"
                            tooltip="{{ trans('admin/hardware/printing.tab_label') }}"
                        />
                    @endif

                    @if (! empty($csiLease))
                        <x-tabs.nav-item
                            name="csi-lease"
                            icon_type="contract"
                            label="{{ trans('admin/hardware/csi.tab_label') }}"
                            tooltip="{{ trans('admin/hardware/csi.tab_label') }}"
                        />
                    @endif

                    @can('view', \App\Models\Order::class)
                        <x-tabs.nav-item
                            name="orders"
                            icon_type="order"
                            label="{{ trans('admin/orders/general.orders') }}"
                            count="{{ $asset->orderItems()->distinct('order_id')->count('order_id') }}"
                            tooltip="{{ trans('admin/orders/general.orders') }}"
                        />
                    @endcan

                    <x-tabs.nav-item
                        name="audits"
                        icon_type="audit"
                        label="{{ trans('general.audits') }}"
                        count="{{ $asset->audits()->count() }}"
                        tooltip="{{ trans('general.audits') }}"
                    />
                    <x-tabs.note-tab :item="$asset" count="{{ $asset->journal->count() }}"/>
                    @php
                        $userAgreementsForAsset = \App\Models\UserAgreement::with('user')
                            ->where('asset_id', $asset->id)
                            ->orderByDesc('created_at')
                            ->get();
                        $assetUploadCount   = $asset->uploads()->count();
                        $modelUploadCount   = $asset->model?->uploads()->count() ?? 0;
                        $agreementPdfCount  = $userAgreementsForAsset->count();
                        $filesTotalCount    = $assetUploadCount + $modelUploadCount + $agreementPdfCount;
                    @endphp
                    <x-tabs.files-tab :item="$asset" count="{{ $filesTotalCount }}"/>
                    <x-tabs.history-tab count="{{ $asset->history()->count() }}" :model="$asset"/>
                    <x-tabs.upload-tab :item="$asset"/>
                </x-slot:tabnav>

                <x-slot:tabpanes>

                    <!-- start details tab content -->
                    <x-tabs.pane name="details">

                        <!-- this just adds a little top space -->
                        <div class="clearfix visible-lg-block" style="padding: 6px;"></div>

                        <!-- identity header: the central catalog facts (manufacturer,
                             category, model, model no.) on top, then the values we read
                             first and touch most — name / tag / serial (inline-editable). -->
                        <x-page-column class="col-md-12">
                            <div class="box box-solid" style="margin-bottom: 12px;">
                                <div class="box-body">
                                    @php $modelOptions = \App\Models\AssetModel::orderBy('name')->pluck('name', 'id'); @endphp
                                    {{-- Top strip: the model catalog facts — super central. Model
                                         is inline-editable; manufacturer/category/model-no derive
                                         from the chosen model and follow it. --}}
                                    <div class="asset-identity-header asset-identity-top">
                                        <div class="asset-identity-field">
                                            <div class="asset-identity-label">{{ trans('general.manufacturer') }}</div>
                                            <div class="asset-identity-subvalue">
                                                @if ($asset->model?->manufacturer){!! $asset->model->manufacturer->present()->nameUrl !!}@else<span class="text-muted">—</span>@endif
                                            </div>
                                        </div>
                                        <div class="asset-identity-field">
                                            <div class="asset-identity-label">{{ trans('general.category') }}</div>
                                            <div class="asset-identity-subvalue">
                                                @if ($asset->model?->category){!! $asset->model->category->present()->nameUrl !!}@else<span class="text-muted">—</span>@endif
                                            </div>
                                        </div>
                                        <div class="asset-identity-field">
                                            <div class="asset-identity-label">{{ trans('general.asset_model') }}</div>
                                            <div class="asset-identity-subvalue">
                                                <x-inline-core-field :asset="$asset" column="model_id" element="select" :options="$modelOptions">{!! $asset->model?->present()->nameUrl !!}</x-inline-core-field>
                                            </div>
                                        </div>
                                        <div class="asset-identity-field">
                                            <div class="asset-identity-label">{{ trans('general.model_no') }}</div>
                                            <div class="asset-identity-subvalue">
                                                {{ $asset->model?->model_number ?: '—' }}
                                            </div>
                                        </div>
                                    </div>

                                    <hr class="asset-identity-divider">

                                    {{-- Name (primary), tag and serial sit together in one
                                         left-aligned row — snug, not pushed to the far right. --}}
                                    <div class="asset-identity-header">
                                        <div class="asset-identity-field asset-identity-name">
                                            <div class="asset-identity-label">{{ trans('general.name') }}</div>
                                            <div class="asset-identity-value">
                                                <x-inline-core-field :asset="$asset" column="name" copy_what="asset_name_hdr"/>
                                            </div>
                                        </div>
                                        <div class="asset-identity-field">
                                            <div class="asset-identity-label">{{ trans('general.asset_tag') }}</div>
                                            <div class="asset-identity-value">
                                                <x-inline-core-field :asset="$asset" column="asset_tag" copy_what="asset_tag_hdr" :editable="false"/>
                                            </div>
                                        </div>
                                        <div class="asset-identity-field">
                                            <div class="asset-identity-label">{{ trans('general.serial_number') }}</div>
                                            <div class="asset-identity-value asset-identity-mono">
                                                <x-inline-core-field :asset="$asset" column="serial" copy_what="serial_hdr" :editable="false"/>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </x-page-column>
                        <div class="clearfix"></div>

                        {{-- One masonry grid of detail cards: the native lifecycle
                             rows, the per-group custom-field boxes, and the EOL /
                             cost / activity stat boxes reflow together across 3
                             columns (Toner-dashboard style, no drag-reorder). The
                             assignment status + checkout dates moved to the sidebar. --}}
                        <x-page-column class="col-md-12">
                            @php
                                // Build per-fieldset render sections keyed by slug, then place
                                // named groups into an explicit 40/60 two-column layout. Inventory
                                // leads with Device Management Service; Decommission Date is shown
                                // in the sidebar, not the grid.
                                $bySlug = [];
                                if (($asset->model) && ($asset->model->fieldset)) {
                                    $sidebarFieldNames = ['Decommission Date'];
                                    $fsFields = $asset->model->fieldset->fields
                                        ->reject(fn ($f) => in_array($f->name, $sidebarFieldNames, true));
                                    $grouped = [];
                                    foreach ($fsFields as $field) {
                                        $grouped[$field->field_group_id ?: 'other'][] = $field;
                                    }
                                    foreach (\App\Models\FieldGroup::ordered()->get() as $fg) {
                                        if (! empty($grouped[$fg->id])) {
                                            $fields = $grouped[$fg->id];
                                            if ($fg->slug === 'inventory') {
                                                usort($fields, fn ($a, $b) =>
                                                    ($b->name === 'Device Management Service') <=> ($a->name === 'Device Management Service'));
                                            }
                                            $bySlug[$fg->slug] = ['group' => $fg, 'fields' => $fields];
                                        }
                                    }
                                    if (! empty($grouped['other'])) {
                                        $bySlug['other'] = ['group' => null, 'fields' => $grouped['other']];
                                    }
                                }
                                // Editable FK option list for the inline Location select.
                                $locationOptions = \App\Models\Location::orderBy('name')->pluck('name', 'id');
                            @endphp

                            <div class="asset-detail-2col">

                                {{-- Left 40%: Inventory / Specs / Networking + lifecycle. --}}
                                <div class="asset-col asset-col-left">
                                    @foreach (['inventory', 'specs', 'networking'] as $slug)
                                        @isset($bySlug[$slug])
                                            @include('hardware._group_card', ['section' => $bySlug[$slug]])
                                        @endisset
                                    @endforeach
                                    @isset($bySlug['other'])
                                        @include('hardware._group_card', ['section' => $bySlug['other']])
                                    @endisset

                                    @if ($asset->hasOrphanedAssignment())
                                        <div class="box box-default asset-card">
                                            <div class="box-body">
                                                <p class="text-danger" style="line-height: 20px;">
                                                    <x-icon type="warning" class="text-danger"/> {{ trans('general.warning', ['warning' => trans('general.item_target_not_found_hard', ['item_type' => $asset->assignedType(), 'id' => $asset->assigned_to])]) }}
                                                </p>
                                                <form action="{{ route('asset.checkin.force', $asset) }}" method="POST" class="form-inline" style="display: inline;">
                                                    {{ csrf_field() }}
                                                    {{ method_field('POST') }}
                                                    <button class="btn btn-sm btn-danger btn-block hidden-print" type="submit" data-tooltip="true" data-placement="top" data-title="{{ trans('general.force_checkin') }}">
                                                        <x-icon type="checkin" class="fa-fw"/>
                                                        {{ trans('general.force_checkin') }}
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    @endif
                                </div>

                                {{-- Right 60%: Procurement / Identifiers, then Costs | Activity. --}}
                                <div class="asset-col asset-col-right">
                                    @foreach (['procurement', 'identity'] as $slug)
                                        @isset($bySlug[$slug])
                                            @include('hardware._group_card', ['section' => $bySlug[$slug]])
                                        @endisset
                                    @endforeach

                                    <div class="asset-subrow">
                                        <div class="box box-default asset-card">
                                            <div class="box-header with-border">
                                                <h3 class="box-title asset-card-title"><i class="fas fa-coins" style="color:#27ae60;" aria-hidden="true"></i> {{ trans('general.detail_card_costs') }}</h3>
                                            </div>
                                            <div class="box-body">
                                                <div class="well-display">
                                                    <x-data-row icon_type="money" :label="trans('general.purchase_cost')" align="right">
                                                        {{ Helper::formatCurrencyOutput($asset->purchase_cost) }}
                                                    </x-data-row>
                                                    <x-data-row icon_type="maintenances" :label="trans('general.maintenances')" align="right">
                                                        {{ Helper::formatCurrencyOutput($total_maintenance_cost) }}
                                                    </x-data-row>
                                                    <x-data-row icon_type="accessories" :label="trans('general.accessories')" align="right">
                                                        {{ Helper::formatCurrencyOutput($total_accessory_cost) }}
                                                    </x-data-row>
                                                    <x-data-row icon_type="licenses" :label="trans('general.licenses')" align="right">
                                                        {{ Helper::formatCurrencyOutput($total_license_cost) }}
                                                    </x-data-row>
                                                    <x-data-row icon_type="components" :label="trans('general.components')" align="right">
                                                        {{ Helper::formatCurrencyOutput($total_component_cost) }}
                                                    </x-data-row>
                                                    <x-data-row icon_type="assets" :label="trans('general.assets')" align="right">
                                                        {{ Helper::formatCurrencyOutput($total_asset_cost) }}
                                                    </x-data-row>
                                                    <x-data-row :label="trans('general.total_cost')" align="right" style="border-top: 1px solid var(--box-header-top-border-color) !important;">
                                                        {{ Helper::formatCurrencyOutput($total_cost_for_asset) }}
                                                    </x-data-row>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="box box-default asset-card">
                                            <div class="box-header with-border">
                                                <h3 class="box-title asset-card-title"><i class="fas fa-wave-square" style="color:#2980b9;" aria-hidden="true"></i> {{ trans('general.detail_card_activity') }}</h3>
                                            </div>
                                            <div class="box-body">
                                                <div class="well-display">
                                                    <x-data-row icon_type="maintenances" label="Active Maintenances" align="right">
                                                        {{ $asset->maintenances->whereNull('completion_date')->count() }}
                                                    </x-data-row>
                                                    <x-data-row icon_type="checkout" :label="trans('general.checkouts_count')" align="right">
                                                        {{ ($asset->checkouts) ? (int) $asset->checkouts->count() : '0' }}
                                                    </x-data-row>
                                                    <x-data-row icon_type="checkin" :label="trans('general.checkins_count')" align="right">
                                                        {{ ($asset->checkins) ? (int) $asset->checkins->count() : '0' }}
                                                    </x-data-row>
                                                    <x-data-row icon_type="request" :label="trans('general.user_requests_count')" align="right">
                                                        {{ ($asset->userRequests) ? (int) $asset->userRequests->count() : '0' }}
                                                    </x-data-row>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            {{-- ./ asset-detail-2col --}}
                        </x-page-column>
                        <!-- end detail grid column -->

                    </x-tabs.pane>

                    <x-tabs.pane name="licenses" :count="$asset->licenses->count()">
                        <x-table.licenses show_search="false" :route="route('api.assets.licenselist', $asset)" :presenter="\App\Presenters\LicensePresenter::dataTableLayoutSeatsCheckedOutToAssets()"/>
                    </x-tabs.pane>

                    <x-tabs.pane name="components" :count="$asset->components->sum('assigned_qty')">
                        <x-table.components :table_header="trans('general.components')" :presenter="\App\Presenters\ComponentPresenter::checkedOut()" :route="route('api.assets.assigned_components', $asset)"/>
                    </x-tabs.pane>

                    <x-tabs.pane name="assets" :count="$asset->assignedAssets()->AssetsForShow()->count()">
                        <x-table.assets :route="route('api.assets.index',['assigned_to' => $asset->id, 'assigned_type' => 'App\Models\Asset'])"/>
                    </x-tabs.pane>

                    <x-tabs.pane name="accessories" :count="$asset->assignedAccessories->count()">
                        <x-slot:table_header>
                            {{ trans('general.accessories_assigned') }}
                        </x-slot:table_header>

                        <x-table
                            name="assetAccessories"
                            buttons="accessoryButtons"
                            api_url="{{ route('api.assets.assigned_accessories', ['asset' => $asset]) }}"
                            :presenter="\App\Presenters\AssetPresenter::assignedAccessoriesDataTableLayout()"
                            export_filename="export-maintenances-{{ str_slug($asset->name) }}-{{ date('Y-m-d') }}"
                        />
                    </x-tabs.pane>


                    <!-- start maintenances tab pane -->
                    <x-tabs.pane name="maintenances">

                        <x-slot:table_header>
                            {{ trans('general.maintenances') }}
                        </x-slot:table_header>

                        <x-table
                            name="assetMaintenances"
                            buttons="maintenanceButtons"
                            api_url="{{ route('api.maintenances.index', array('asset_id' => $asset->id)) }}"
                            :presenter="\App\Presenters\MaintenancesPresenter::dataTableLayout()"
                            export_filename="export-maintenances-{{ str_slug($asset->name) }}-{{ date('Y-m-d') }}"
                        />
                    </x-tabs.pane>
                    <!-- end maintenances tab pane -->

                    @if (! empty($printerUsage))
                        <x-tabs.pane name="printing">
                            @include('hardware.printing', ['usage' => $printerUsage])
                        </x-tabs.pane>
                    @endif

                    @if (! empty($csiLease))
                        <x-tabs.pane name="csi-lease">
                            @include('hardware.csi-lease', ['csi' => $csiLease, 'asset' => $asset])
                        </x-tabs.pane>
                    @endif

                    <!-- start audits tab pane -->
                    <x-tabs.pane name="audits">
                        <x-table.history
                            :table_header="trans('general.audits')"
                            :model="$asset"
                            :route="route('api.activity.index', ['item_id' => $asset->id, 'item_type' => 'asset', 'action_type' => 'audit'])"
                            :hide_fields="['id','action_type', 'item', 'changed', 'target','quantity','changed','serial','signature_file','log_meta']"/>
                    </x-tabs.pane>
                    <!-- end audits tab pane -->

                    <!-- start notes tab pane -->
                    <x-tabs.pane name="notes">
                        <x-table.history
                            :table_header="trans('general.notes')"
                            :model="$asset" :route="route('api.activity.index', ['item_id' => $asset->id, 'item_type' => 'asset', 'action_type' => 'note added'])"
                            :hide_fields="['id','action_type', 'item', 'changed', 'target','file','file_download','quantity','changed','serial','signature_file','log_meta']"
                        />
                    </x-tabs.pane>
                    <!-- end audits tab pane -->


                    <x-tabs.pane name="files">
                        @if ($agreementPdfCount > 0)
                            <div class="box box-default">
                                <div class="box-header with-border">
                                    <h3 class="box-title">{{ trans('admin/user-agreements/general.agreement_documents') }}</h3>
                                </div>
                                <div class="box-body table-responsive">
                                    <table class="table table-striped" style="margin-bottom:0;">
                                        <thead>
                                            <tr>
                                                <th>{{ trans('admin/user-agreements/general.type') }}</th>
                                                <th>{{ trans('admin/user-agreements/general.user') }}</th>
                                                <th>{{ trans('admin/user-agreements/general.stage') }}</th>
                                                <th>{{ trans('admin/user-agreements/general.generated') }}</th>
                                                <th class="text-right">{{ trans('table.actions') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        @foreach ($userAgreementsForAsset as $agreement)
                                            <tr>
                                                <td>
                                                    <a href="{{ route('user-agreements.show', $agreement) }}">
                                                        {{ trans('admin/user-agreements/general.type_'.($agreement->agreement_type === 'purchase' ? 'purchase' : $agreement->agreement_type)) }}
                                                    </a>
                                                </td>
                                                <td>
                                                    @if ($agreement->user)
                                                        <a href="{{ route('users.show', $agreement->user) }}">{{ $agreement->user->full_name }}</a>
                                                    @else — @endif
                                                </td>
                                                <td>{{ trans('admin/purchase-orders/general.user_agreement_stage_value_'.$agreement->lifecycle_stage) }}</td>
                                                <td>
                                                    @if ($agreement->pdf_generated_at)
                                                        {{ \App\Helpers\Helper::getFormattedDateObject($agreement->pdf_generated_at, 'datetime', false) }}
                                                    @else
                                                        <span class="text-muted">—</span>
                                                    @endif
                                                </td>
                                                <td class="text-right">
                                                    <a class="btn btn-default btn-sm" href="{{ route('user-agreements.pdf', $agreement) }}" target="_blank" title="{{ $agreement->signed_pdf_path ? trans('admin/user-agreements/general.download_signed_pdf') : trans('admin/user-agreements/general.preview_pdf') }}">
                                                        <i class="fas {{ $agreement->signed_pdf_path ? 'fa-download' : 'fa-file-pdf' }}"></i>
                                                    </a>
                                                    @if (! $agreement->signed_pdf_path && $agreement->user_id)
                                                        <form method="POST" action="{{ route('user-agreements.pregen-pdf', $agreement) }}" style="display:inline-block; margin:0;">
                                                            {{ csrf_field() }}
                                                            <button type="submit" class="btn btn-default btn-sm" title="{{ trans('admin/user-agreements/general.regenerate_pdf_help') }}">
                                                                <i class="fas fa-sync-alt"></i>
                                                            </button>
                                                        </form>
                                                    @endif
                                                    @if (! $agreement->checkout_acceptance_id && $agreement->user_id)
                                                        <form method="POST" action="{{ route('user-agreements.send-for-signature', $agreement) }}" style="display:inline-block; margin:0;">
                                                            {{ csrf_field() }}
                                                            <button type="submit" class="btn btn-primary btn-sm" title="{{ trans('admin/user-agreements/general.send_for_signature') }}">
                                                                <i class="fas fa-paper-plane"></i>
                                                            </button>
                                                        </form>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endif

                        @if ($assetUploadCount > 0)
                            <x-table.files object_type="assets" :object="$asset"/>
                        @endif

                        @if ($asset->model && $modelUploadCount > 0)
                            <x-table.files :table_header="trans('general.additional_files')" object_type="models" :object="$asset->model"/>
                        @endif

                        @if ($filesTotalCount === 0)
                            <div class="alert alert-info" style="margin-top:0;">
                                {{ trans('general.no_files_uploaded') }}
                            </div>
                        @endif
                    </x-tabs.pane>

                    @can('view', \App\Models\Order::class)
                        <!-- start orders tab pane -->
                        <x-tabs.pane name="orders">
                            @php
                                $assetOrders = \App\Models\Order::whereIn('id', $asset->orderItems()->pluck('order_id')->unique())
                                    ->with('supplier')
                                    ->orderBy('order_date', 'desc')
                                    ->get();
                            @endphp
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>{{ trans('general.order_number') }}</th>
                                        <th>{{ trans('admin/orders/general.status') }}</th>
                                        <th>{{ trans('general.supplier') }}</th>
                                        <th>{{ trans('admin/orders/general.order_date') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                @forelse ($assetOrders as $assetOrder)
                                    <tr>
                                        <td><a href="{{ route('orders.show', $assetOrder->id) }}">{{ $assetOrder->order_number }}</a></td>
                                        <td>{{ trans('admin/orders/general.status_'.$assetOrder->status) }}</td>
                                        <td>{{ $assetOrder->supplier?->name }}</td>
                                        <td>{{ $assetOrder->order_date ? $assetOrder->order_date->format('Y-m-d') : '' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4">{{ trans('admin/orders/message.not_linked') }}</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </x-tabs.pane>
                        <!-- end orders tab pane -->
                    @endcan

                    <!-- start history tab pane -->
                    <x-tabs.pane name="history">
                        <x-table.history
                            :model="$asset"
                            :route="route('api.assets.history', $asset)"
                        />
                    </x-tabs.pane>
                    <!-- end history tab pane -->


                </x-slot:tabpanes>

            </x-tabs>

        </x-page-column>

        <x-page-column class="col-md-3 col-md-pull-9">

            {{-- Everything assignment/lifecycle lives INSIDE the one info-panel box
                 (not a separate box on top): status + checkout dates in before_list,
                 then the audit / default-location / decommission / last-note rows that
                 moved out of the removed Details card render at the top of the list. --}}
            <x-box class="side-box expanded">
                <x-info-panel :infoPanelObj="$asset" img_path="{{ app('assets_upload_url') }}">
                    <x-slot:buttons>

                        @if (!$asset->assignedTo)
                        <x-button.checkout permission="checkout" :item="$asset" :route="route('hardware.checkout.create', $asset->id)"/>
                        @endif

                        @if (!$asset->hasOrphanedAssignment())
                            <x-button.checkin permission="checkin" :item="$asset" :route="route('hardware.checkin.create', $asset->id)"/>
                        @endif

                        <x-button.edit :item="$asset" :route="route('hardware.edit', $asset->id)"/>
                        <x-button.clone :item="$asset" :route="route('clone/hardware', $asset->id)"/>
                        <x-button.note :item="$asset" :route="route('clone/hardware', $asset->id)"/>
                        <x-button.audit :item="$asset" :route="route('asset.audit.create', $asset->id)"/>
                        <x-button.label :item="$asset" :route="route('hardware.bulkedit.show')"/>
                        <x-button.delete :item="$asset"/>
                        <x-button.restore :item="$asset" :route="route('restore/hardware', ['asset' => $asset->id])"/>
                    </x-slot:buttons>

                    {{-- Assignment status, checkout dates, audit + decommission all
                         render as uniform sidebar rows (consistent dividers/spacing),
                         at the top of the info list. Default Location is now the
                         editable Location in Inventory. --}}
                    <x-info-element title="{{ trans('general.status') }}">
                        <x-info-element.status :infoObject="$asset"/>
                    </x-info-element>

                    <x-info-element icon_type="calendar" title="{{ trans('general.last_checkout') }}">
                        {{ trans('general.last_checkout') }}
                        <span class="pull-right">
                            @if ($asset->last_checkout != '')
                                {{ Helper::getFormattedDateObject($asset->last_checkout, 'date', false) }}
                            @endif
                        </span>
                    </x-info-element>

                    <x-info-element icon_type="expected_checkin" title="{{ trans('general.expected_checkin') }}">
                        {{ trans('general.expected_checkin') }}
                        <span class="pull-right">
                            <x-inline-core-field :asset="$asset" column="expected_checkin" element="date">{{ $asset->expected_checkin ? Helper::getFormattedDateObject($asset->expected_checkin, 'date', false) : '' }}</x-inline-core-field>
                        </span>
                    </x-info-element>

                    <x-info-element icon_type="audit" title="{{ trans('general.last_audit') }}">
                        {{ trans('general.last_audit') }}
                        <span class="pull-right">
                            <x-inline-core-field :asset="$asset" column="last_audit_date" element="date">{{ $asset->last_audit_date ? Helper::getFormattedDateObject($asset->last_audit_date, 'date', false) : '' }}</x-inline-core-field>
                        </span>
                    </x-info-element>

                    <x-info-element icon_type="audit" title="{{ trans('general.next_audit_date') }}">
                        {{ trans('general.next_audit_date') }}
                        <span class="pull-right">
                            <x-inline-core-field :asset="$asset" column="next_audit_date" element="date">{{ $asset->next_audit_date ? Helper::getFormattedDateObject($asset->next_audit_date, 'date', false) : '' }}</x-inline-core-field>
                        </span>
                    </x-info-element>

                    @php $decommField = $asset->model?->fieldset?->fields->firstWhere('name', 'Decommission Date'); @endphp
                    @if ($decommField)
                        <x-info-element icon_type="calendar" title="{{ $decommField->name }}">
                            {{ $decommField->name }}
                            <span class="pull-right"><x-inline-custom-field :asset="$asset" :field="$decommField"/></span>
                        </x-info-element>
                    @endif
                </x-info-panel>
            </x-box>

            {{-- Lifecycle: EOL / depreciation / warranty progress — a special box
                 of its own, above Metadata. --}}
            @if(($asset->purchase_date && $asset->asset_eol_date) || $asset->depreciated_date() || $asset->warranty_expires)
                <div class="box box-default side-box">
                    <div class="box-header with-border">
                        <h3 class="box-title asset-card-title"><i class="fas fa-hourglass-half" style="color:#c0392b;" aria-hidden="true"></i> {{ trans('general.detail_card_lifecycle') }}</h3>
                    </div>
                    <div class="box-body">
                        @if($asset->purchase_date && $asset->asset_eol_date)
                            <x-progressbar use_well="false" columns="12" text="{{ trans('general.device_eol') }}" :percent="$asset->eolProgressPercent()">
                                (<strong>{{ (int) Carbon::now()->diffInMonths($asset->asset_eol_date, true) }}</strong>/{{ $asset->model?->eol }} {{ trans('general.months') }})
                            </x-progressbar>
                        @endif

                        @if($asset->depreciated_date())
                            <x-progressbar use_well="false" columns="12" :text="trans('admin/hardware/form.fully_depreciated')" :percent="$asset->depreciationProgressPercent()">
                                {{ Helper::getFormattedDateObject($asset->depreciated_date()->format('Y-m-d'), 'date', false) }}
                            </x-progressbar>
                        @endif

                        @if($asset->warranty_expires)
                            <x-progressbar use_well="false" columns="12" :text="trans('admin/hardware/form.warranty_expires')" :percent="$asset->warrantyProgressPercent()">
                            {{ Helper::getFormattedDateObject($asset->warranty_expires, 'date', false) }}
                            </x-progressbar>
                        @endif
                    </div>
                </div>
            @endif

            {{-- Metadata: low-signal flags + provenance, styled like the group
                 cards (label/value rows with inset dividers). --}}
            <div class="box box-default side-box asset-card">
                <div class="box-header with-border">
                    <h3 class="box-title asset-card-title"><i class="fas fa-database" style="color:#7f8c8d;" aria-hidden="true"></i> {{ trans('general.metadata') }}</h3>
                </div>
                <div class="box-body asset-card-body">
                    @if (isset($asset->byod))
                        <div class="asset-card-row">
                            <div class="asset-card-lbl">{{ trans('general.byod') }}</div>
                            <div class="asset-card-val">@if ($asset->byod == 1)<x-icon type="checkmark" class="text-success"/> {{ trans('general.yes') }}@else<x-icon type="x" class="text-danger"/> {{ trans('general.no') }}@endif</div>
                        </div>
                    @endif
                    @if (isset($asset->requestable))
                        <div class="asset-card-row">
                            <div class="asset-card-lbl">{{ trans('admin/hardware/general.requestable') }}</div>
                            <div class="asset-card-val">@if ($asset->requestable == 1)<x-icon type="checkmark" class="text-success"/> {{ trans('general.yes') }}@else<x-icon type="x" class="text-danger"/> {{ trans('general.no') }}@endif</div>
                        </div>
                    @endif
                    @if ($asset->adminuser)
                        <div class="asset-card-row">
                            <div class="asset-card-lbl">{{ trans('general.created_by') }}</div>
                            <div class="asset-card-val">{!! $asset->adminuser->present()->formattedNameLink !!}</div>
                        </div>
                    @endif
                    @if ($asset->created_at)
                        <div class="asset-card-row">
                            <div class="asset-card-lbl">{{ trans('general.created_plain') }}</div>
                            <div class="asset-card-val">{{ Helper::getFormattedDateObject($asset->created_at, 'datetime', false) }}</div>
                        </div>
                    @endif
                    @if ($asset->updated_at)
                        <div class="asset-card-row">
                            <div class="asset-card-lbl">{{ trans('general.updated_plain') }}</div>
                            <div class="asset-card-val">{{ Helper::getFormattedDateObject($asset->updated_at, 'datetime', false) }}</div>
                        </div>
                    @endif
                </div>
            </div>

            {{-- QR code — just the code, no box/thumbnail frame. --}}
            @if (($snipeSettings->qr_code=='1') || $snipeSettings->label2_2d_type!='none')
                <div class="text-center" style="padding: 16px 0 24px;">
                    <img src="{{ config('app.url') }}/hardware/{{ $asset->id }}/qr_code" style="height: 150px; width: 150px;" alt="QR code for {{ $asset->getDisplayNameAttribute() }}">
                </div>
            @endif

        </x-page-column>

    </x-container>


    @section('moar_scripts')
        @can('files', $asset)
            @include ('modals.upload-file', ['item_type' => 'asset', 'item_id' => $asset->id])
        @endcan
        @can('update', $asset)
        @include ('modals.add-note', ['type' => 'asset', 'id' => $asset->id])
    @endcan
        @include ('partials.bootstrap-table')

        {{-- Inline single-field edit on the grouped detail boxes. Progressive:
             without JS the edit forms stay hidden and the full edit form still
             works; with JS the pencil swaps the value for an input/select + save. --}}
        <script nonce="{{ csrf_token() }}">
            $(function () {
                function showForm(target) {
                    $('#' + target + '-display').hide();
                    $('#' + target + '-form').show().find('input[name="value"], textarea[name="value"], select[name="value"]').first().focus();
                }
                function hideForm(target) {
                    $('#' + target + '-form').hide();
                    $('#' + target + '-display').show();
                }
                $(document).on('click', '.js-inline-edit-toggle', function (e) {
                    e.preventDefault();
                    showForm($(this).data('target'));
                });
                $(document).on('click', '.js-inline-edit-cancel', function (e) {
                    e.preventDefault();
                    hideForm($(this).data('target'));
                });
            });
        </script>
    @endsection

    @push('css')
        <style>
            /* Identity header: name (primary), tag and serial in one left-aligned
               row — snug, not pushed to the far right. */
            .asset-identity-header { display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 40px; }
            .asset-identity-name { margin-right: 4px; }
            .asset-identity-label { font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: #999; }
            .asset-identity-value { font-size: 20px; font-weight: 600; line-height: 1.4; }
            .asset-identity-mono { font-family: SFMono-Regular, Menlo, Consolas, monospace; }
            /* Top catalog strip — secondary to name/tag/serial: smaller values. */
            .asset-identity-top { gap: 4px 32px; }
            .asset-identity-top .asset-identity-subvalue { font-size: 14px; font-weight: 600; line-height: 1.4; }
            .asset-identity-divider { margin: 10px 0; border-top: 1px solid #ececec; }

            /* Two-column detail layout: 40% left (Inventory/Specs/Networking),
               60% right (Procurement/Identifiers, then Costs|Activity). Cards
               stack within each column; the wider right column gives the long
               procurement/identifier values room so labels never overlap. */
            .asset-detail-2col { display: flex; flex-wrap: wrap; gap: 18px; align-items: flex-start; width: 100%; }
            .asset-col { display: flex; flex-direction: column; gap: 18px; min-width: 0; }
            .asset-col-left  { flex: 1 1 36%; }
            .asset-col-right { flex: 1 1 58%; }
            .asset-detail-2col .asset-card { margin: 0; }
            /* Costs | Activity share the bottom of the right column, 50/50. */
            .asset-subrow { display: flex; flex-wrap: wrap; gap: 18px; align-items: flex-start; }
            .asset-subrow > .asset-card { flex: 1 1 45%; min-width: 0; margin: 0; }
            @media (max-width: 991px) {
                .asset-col-left, .asset-col-right { flex: 1 1 100%; }
            }

            /* Cards: drop the coloured top border (kept the coloured header icon). */
            .asset-card { border-top: none !important; }
            .asset-card-title { font-size: 15px; font-weight: 600; }
            .asset-card-title i { margin-right: 6px; }

            /* Field rows share a 2-column grid per card: the label column
               auto-sizes to the widest label in that card (so "Device Management
               Service" stays on one line) and every value lines up at the same x.
               Rows use display:contents so their label/value join the card grid. */
            .asset-card-body {
                padding-top: 6px; padding-bottom: 6px;
                display: grid;
                grid-template-columns: max-content minmax(0, 1fr);
                column-gap: 0;
            }
            /* AdminLTE's .box-body has clearfix ::before/::after (content:" ";
               display:table). On a grid container those pseudo-elements become
               phantom grid items — the leading ::before takes the first cell and
               shifts every label into the value column. Suppress them. */
            .asset-card-body::before,
            .asset-card-body::after { content: none; display: none; }
            .asset-card-row { display: contents; }
            .asset-card-lbl { font-weight: 600; padding: 8px 18px 8px 0; border-bottom: 1px solid #f1f1f1; }
            .asset-card-val { min-width: 0; padding: 8px 0; border-bottom: 1px solid #f1f1f1; word-break: break-word; }
            .asset-card-row:last-child .asset-card-lbl,
            .asset-card-row:last-child .asset-card-val { border-bottom: none; }

            /* Sidebar rows (status / checkout / audit / metadata) — consistent
               breathing room and light dividers. */
            .side-box .list-group-item { padding: 10px 14px; border-color: #f1f1f1 !important; }
        </style>
    @endpush

@stop

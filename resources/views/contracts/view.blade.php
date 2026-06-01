@extends('layouts/default')

@section('title')
    {{ trans('admin/contracts/general.view') }} — {{ $contract->name }}
    @parent
@stop

@section('header_right')
    @can('update', $contract)
        <a href="{{ route('contracts.edit', $contract) }}" class="btn btn-primary pull-right" style="margin-left:5px;">
            {{ trans('general.edit') }}
        </a>
    @endcan
    @if ($contract->tdx_id)
        <a href="https://servicedesk.emilycarru.ca/TDNext/Apps/116/Assets/Contracts?ContractID={{ $contract->tdx_id }}"
           target="_blank" rel="noopener"
           class="label label-info pull-right" style="margin:5px;"
           data-tooltip="true" title="{{ trans('admin/contracts/general.open_in_tdx') }}">
            {{ trans('admin/contracts/general.tdx_id') }}: {{ $contract->tdx_id }}
            <x-icon type="external-link" class="fa-fw"/>
        </a>
    @endif
@stop

@section('content')
    <x-container columns="2">
        <x-page-column class="col-md-8 main-panel">
            <x-box>
                <h2 style="margin-top:0;">
                    {{ $contract->name }}
                    @if ($contract->is_synthesized)
                        <small class="label label-default">{{ trans('admin/contracts/general.synthesized_umbrella') }}</small>
                    @endif
                </h2>
                <p class="text-muted" style="margin-bottom:15px;">{{ $contract->contract_number }}</p>

                <table class="table table-striped">
                    <tbody>
                        <tr><th style="width:30%">{{ trans('admin/contracts/general.theme') }}</th><td>{{ $contract->theme ?? '—' }}</td></tr>
                        <tr><th>{{ trans('admin/contracts/general.product') }}</th><td>{{ $contract->product ?? '—' }}</td></tr>
                        <tr><th>{{ trans('admin/contracts/general.fiscal_year') }}</th><td>{{ $contract->fiscal_year ?? '—' }}</td></tr>
                        <tr><th>{{ trans('admin/contracts/general.contract_type') }}</th><td>{{ $contract->type ?? '—' }}</td></tr>
                        <tr><th>{{ trans('admin/contracts/general.workflow_status') }}</th><td>{{ $contract->workflow_status ?? '—' }}</td></tr>
                        <tr><th>{{ trans('general.supplier') }}</th><td>
                            @if ($contract->supplier)
                                <a href="{{ route('suppliers.show', $contract->supplier) }}">{{ $contract->supplier->name }}</a>
                            @else — @endif
                        </td></tr>
                        <tr><th>{{ trans('admin/contracts/general.admin_user') }}</th><td>
                            @if ($contract->owner)
                                <a href="{{ route('users.show', $contract->owner) }}">{{ $contract->owner->present()->fullName }}</a>
                                @if ($contract->owner->email)
                                    <span class="text-muted">&lt;{{ $contract->owner->email }}&gt;</span>
                                @endif
                            @else
                                <span class="text-muted">{{ trans('admin/contracts/general.admin_user_unset') }}</span>
                            @endif
                        </td></tr>
                        <tr><th>{{ trans('admin/contracts/general.start_date') }}</th><td>{{ optional($contract->start_date)->toDateString() ?? '—' }}</td></tr>
                        <tr><th>{{ trans('admin/contracts/general.end_date') }}</th><td>{{ optional($contract->end_date)->toDateString() ?? '—' }}</td></tr>
                        <tr><th>{{ trans('admin/contracts/general.total_cost') }}</th><td>{{ $contract->total_cost ? '$' . \App\Helpers\Helper::formatCurrencyOutput($contract->total_cost) . ' ' . $contract->currency : '—' }}</td></tr>
                        @php $rollup = $contract->childrenCostSum(); @endphp
                        @if ($rollup !== null)
                            <tr>
                                <th>{{ trans('admin/contracts/general.children_cost_sum') }}</th>
                                <td>
                                    ${{ \App\Helpers\Helper::formatCurrencyOutput($rollup) }} {{ $contract->currency }}
                                    <small class="text-muted">
                                        ({{ trans('admin/contracts/general.children_cost_sum_help', ['count' => $contract->children->count()]) }})
                                    </small>
                                </td>
                            </tr>
                        @endif
                        <tr><th>{{ trans('admin/contracts/general.gl_code') }}</th><td>{{ $contract->gl_code ?? '—' }}</td></tr>
                        <tr><th>{{ trans('admin/contracts/general.requisition_number') }}</th><td>{{ $contract->requisition_number ?? '—' }}</td></tr>
                        <tr><th>{{ trans('admin/contracts/general.voucher_number') }}</th><td>{{ $contract->voucher_number ?? '—' }}</td></tr>
                        <tr><th>{{ trans('admin/contracts/general.service_offering') }}</th><td>{{ $contract->service_offering ?? '—' }}</td></tr>
                        <tr><th>{{ trans('admin/contracts/general.schedule_number') }}</th><td>{{ $contract->schedule_number ?? '—' }}</td></tr>
                        <tr><th>{{ trans('admin/contracts/general.ticket_url') }}</th><td>
                            @if ($contract->ticket_url)
                                <a href="{{ $contract->ticket_url }}" target="_blank" rel="noopener">{{ $contract->ticket_url }}</a>
                            @else — @endif
                        </td></tr>
                        <tr><th>{{ trans('admin/contracts/general.source') }}</th><td>{{ $contract->source }}</td></tr>
                    </tbody>
                </table>

                @if ($contract->description)
                    <h4>{{ trans('admin/contracts/general.description') }}</h4>
                    <div class="well well-sm">{!! \App\Helpers\Helper::parseEscapedMarkedown($contract->description) !!}</div>
                @endif

                @if ($contract->comments_review)
                    <h4>{{ trans('admin/contracts/general.comments_review') }}</h4>
                    <div class="well well-sm">{!! \App\Helpers\Helper::parseEscapedMarkedown($contract->comments_review) !!}</div>
                @endif

                @if ($contract->notes)
                    <h4>{{ trans('general.notes') }}</h4>
                    <div class="well well-sm">{!! \App\Helpers\Helper::parseEscapedMarkedown($contract->notes) !!}</div>
                @endif
            </x-box>

            @can('files', $contract)
                <x-box>
                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px;">
                        <h3 style="margin:0;">
                            {{ trans('general.files') }}
                            <small class="text-muted">({{ $contract->uploads()->count() }})</small>
                        </h3>
                        <button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#uploadFileModal">
                            <i class="fas fa-upload" aria-hidden="true"></i>
                            {{ trans('general.file_upload') }}
                        </button>
                    </div>
                    <x-table.files object_type="contracts" :object="$contract"/>
                </x-box>
            @endcan
        </x-page-column>

        <x-page-column class="col-md-4 side-panel">
            <x-box>
                <h3>{{ trans('admin/contracts/general.hierarchy') }}</h3>
                @if ($contract->parent)
                    <p>
                        <strong>{{ trans('admin/contracts/general.parent') }}:</strong>
                        <a href="{{ route('contracts.show', $contract->parent) }}">{{ $contract->parent->name }}</a>
                    </p>
                @endif
                @if ($contract->children->isNotEmpty())
                    <p><strong>{{ trans('admin/contracts/general.children') }} ({{ $contract->children->count() }}):</strong></p>
                    <ul>
                        @foreach ($contract->children as $child)
                            <li>
                                <a href="{{ route('contracts.show', $child) }}">{{ $child->name }}</a>
                                @if ($child->fiscal_year) <span class="text-muted">— {{ $child->fiscal_year }}</span> @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if (! $contract->parent && $contract->children->isEmpty())
                    <p class="text-muted">{{ trans('admin/contracts/general.no_hierarchy') }}</p>
                @endif
            </x-box>

            <x-box>
                <h3>{{ trans('general.licenses') }} ({{ $contract->licenses->count() }})</h3>
                @if ($contract->licenses->isNotEmpty())
                    <ul>
                        @foreach ($contract->licenses as $license)
                            <li>
                                <a href="{{ route('licenses.show', $license) }}">{{ $license->name }}</a>
                                @if ($license->seats)
                                    <span class="text-muted">— {{ $license->seats }} {{ trans('admin/contracts/general.seats') }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-muted">{{ trans('admin/contracts/general.no_licenses') }}</p>
                @endif
            </x-box>

            <x-box>
                <h3>{{ trans('general.assets') }} ({{ $contract->assets->count() }})</h3>
                @if ($contract->assets->isNotEmpty())
                    <ul>
                        @foreach ($contract->assets->take(20) as $asset)
                            <li><a href="{{ route('hardware.show', $asset) }}">{{ $asset->asset_tag }} — {{ $asset->name ?? $asset->serial }}</a></li>
                        @endforeach
                        @if ($contract->assets->count() > 20)
                            <li class="text-muted">+ {{ $contract->assets->count() - 20 }} {{ trans('general.more') }}</li>
                        @endif
                    </ul>
                @else
                    <p class="text-muted">{{ trans('admin/contracts/general.no_assets') }}</p>
                @endif
            </x-box>

            <x-box>
                <h3>{{ trans('admin/contracts/general.serials') }} ({{ $contract->serials->count() }})</h3>
                @if ($contract->serials->isNotEmpty())
                    <table class="table table-condensed">
                        <thead><tr><th>{{ trans('admin/contracts/general.serial') }}</th><th>{{ trans('admin/contracts/general.source') }}</th></tr></thead>
                        <tbody>
                            @foreach ($contract->serials as $row)
                                <tr><td><code>{{ $row->serial }}</code></td><td><span class="text-muted">{{ $row->source }}</span></td></tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <p class="text-muted">{{ trans('admin/contracts/general.no_serials') }}</p>
                @endif
            </x-box>

            @if ($contract->attributes->isNotEmpty())
                <x-box>
                    <h3>{{ trans('admin/contracts/general.extra_attributes') }}</h3>
                    <table class="table table-condensed">
                        <tbody>
                            @foreach ($contract->attributes as $attr)
                                <tr><th>{{ $attr->name }}</th><td>{{ $attr->value }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-box>
            @endif
        </x-page-column>
    </x-container>
@stop

@section('moar_scripts')
    @can('files', $contract)
        @include ('modals.upload-file', ['item_type' => 'contract', 'item_id' => $contract->id])
    @endcan
    @include ('partials.bootstrap-table')
@endsection

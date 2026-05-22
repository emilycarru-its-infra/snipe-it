@extends('layouts/default')

{{-- Page title --}}
@section('title')
  {{ $consumable->name }}
  {{ trans('general.consumable') }} -
  ({{ trans('general.remaining_var', ['count' => $consumable->numRemaining()])  }})
  @parent
@endsection

@section('header_right')
    <x-button.info-panel-toggle/>
@endsection

{{-- Page content --}}
@section('content')

    <x-container columns="2">
        <x-page-column class="col-md-9 main-panel">
            <x-tabs>
                <x-slot:tabnav>

                    <x-tabs.nav-item
                            name="assigned"
                            class="active"
                            icon_type="checkedout"
                            label="{{ trans('general.assigned') }}"
                            count="{{ $consumable->numCheckedOut() }}"
                    />

                    <x-tabs.files-tab :item="$consumable" count="{{ $consumable->uploads()->count() }}"/>
                    <x-tabs.history-tab count="{{ $consumable->history()->count() }}" :model="$consumable"/>

                    <x-tabs.nav-item
                            name="gl-transactions"
                            icon="fa-solid fa-money-bill-transfer"
                            label="{{ trans('admin/consumables/general.gl_transactions') }}"
                            count="{{ $consumable->transactions()->count() }}"
                    />

                    <x-tabs.upload-tab :item="$consumable"/>

                </x-slot:tabnav>

                <x-slot:tabpanes>

                    <x-tabs.pane name="assigned">

                        <x-table
                            :presenter="\App\Presenters\ConsumablePresenter::checkedOut()"
                            :api_url="route('api.consumables.show.users', $consumable->id)"
                        />

                    </x-tabs.pane>

                    <x-tabs.pane name="files">
                        <x-table.files object_type="consumables" :object="$consumable"/>
                    </x-tabs.pane>

                    <!-- start history tab pane -->
                    <x-tabs.pane name="history">
                        <x-table.history :model="$consumable" :route="route('api.consumables.history', $consumable)"/>
                    </x-tabs.pane>
                    <!-- end history tab pane -->

                    <!-- start GL transactions tab pane -->
                    <x-tabs.pane name="gl-transactions">
                        @php $glTransactions = $consumable->transactions()->with('asset')->get(); @endphp
                        @if ($glTransactions->isEmpty())
                            <p class="text-muted" style="padding: 10px 0;">
                                {{ trans('admin/consumables/general.gl_transactions_empty') }}
                            </p>
                        @else
                            <table class="table table-striped snipe-table">
                                <thead>
                                    <tr>
                                        <th>{{ trans('admin/consumables/general.gl_txn_date') }}</th>
                                        <th>{{ trans('admin/consumables/general.gl_txn_printer') }}</th>
                                        <th>{{ trans('admin/consumables/general.gl_txn_code') }}</th>
                                        <th class="text-right">{{ trans('admin/consumables/general.gl_txn_qty') }}</th>
                                        <th class="text-right">{{ trans('admin/consumables/general.gl_txn_unit_cost') }}</th>
                                        <th class="text-right">{{ trans('admin/consumables/general.gl_txn_total') }}</th>
                                        <th>{{ trans('admin/consumables/general.gl_txn_fiscal_year') }}</th>
                                        <th>{{ trans('admin/consumables/general.gl_txn_status') }}</th>
                                        @can('update', $consumable)
                                            <th class="text-right">{{ trans('table.actions') }}</th>
                                        @endcan
                                    </tr>
                                </thead>
                                <tbody>
                                @foreach ($glTransactions as $txn)
                                    <tr>
                                        <td>{{ $txn->transaction_date?->format('Y-m-d') }}</td>
                                        <td>
                                            @if ($txn->asset)
                                                <a href="{{ route('hardware.show', $txn->asset->id) }}">{{ $txn->asset->present()->name() }}</a>
                                            @endif
                                        </td>
                                        <td>{{ $txn->gl_code }}</td>
                                        <td class="text-right">{{ $txn->quantity }}</td>
                                        <td class="text-right">{{ \App\Helpers\Helper::formatCurrencyOutput($txn->unit_cost) }}</td>
                                        <td class="text-right">{{ \App\Helpers\Helper::formatCurrencyOutput($txn->total_cost) }}</td>
                                        <td>{{ $txn->fiscal_year }}</td>
                                        <td>{{ ucfirst($txn->status) }}</td>
                                        @can('update', $consumable)
                                            <td class="text-right" style="white-space:nowrap;">
                                                <a href="{{ route('consumables.transactions.edit', [$consumable->id, $txn->id]) }}"
                                                   class="btn btn-sm btn-default" data-tooltip="true"
                                                   title="{{ trans('admin/consumables/general.edit_transaction') }}">
                                                    <i class="fa-solid fa-pencil" aria-hidden="true"></i>
                                                </a>
                                                <form method="post" style="display:inline;"
                                                      action="{{ route('consumables.transactions.void', [$consumable->id, $txn->id]) }}"
                                                      onsubmit="return confirm('{{ trans('admin/consumables/general.void_transaction_confirm') }}');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-danger" data-tooltip="true"
                                                            title="{{ trans('admin/consumables/general.void_transaction') }}">
                                                        <i class="fa-solid fa-ban" aria-hidden="true"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        @endcan
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        @endif
                    </x-tabs.pane>
                    <!-- end GL transactions tab pane -->

                </x-slot:tabpanes>

            </x-tabs>
        </x-page-column>

        <x-page-column class="col-md-3">
            <x-box class="side-box expanded">
                <x-info-panel :infoPanelObj="$consumable" img_path="{{ app('consumables_upload_url') }}">

                    <x-slot:buttons>
                        <x-button.edit :item="$consumable" :route="route('consumables.edit', $consumable->id)"/>
                        <x-button.clone :item="$consumable" :route="route('consumables.clone.create', $consumable->id)"/>
                        <x-button.delete :item="$consumable"/>
                        <x-button.checkout :item="$consumable" :route="route('consumables.checkout.show', $consumable->id)" />
                        @can('checkout', $consumable)
                            <a href="{{ route('consumables.order.create', $consumable->id) }}" class="btn btn-sm btn-warning">
                                <i class="fa-solid fa-cart-plus" aria-hidden="true"></i>
                                {{ trans('admin/consumables/general.order') }}
                            </a>
                        @endcan
                    </x-slot:buttons>

                </x-info-panel>
            </x-box>
        </x-page-column>
    </x-container>

@endsection

@section('moar_scripts')
    @can('files', $consumable)
        @include ('modals.upload-file', ['item_type' => 'consumables', 'item_id' => $consumable->id])
    @endcan

    @include ('partials.bootstrap-table', ['exportFile' => 'consumable-' . $consumable->name . '-export', 'search' => false])
@endsection


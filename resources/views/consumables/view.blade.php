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
        {{-- Info-panel moved to the LEFT; the tables/tabs sit on the right. --}}
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

        <x-page-column class="col-md-9 main-panel">
            <x-tabs>
                <x-slot:tabnav>

                    {{-- Activity is the default tab now — one merged timeline of
                         GL transactions + action-log history, which is what the
                         reconciliation workflow opens to most often. --}}
                    <x-tabs.nav-item
                            name="activity"
                            class="active"
                            icon="fa-solid fa-clock-rotate-left"
                            label="{{ trans('admin/consumables/general.activity') }}"
                            count="{{ $consumable->transactions()->count() + $consumable->history()->count() }}"
                    />

                    <x-tabs.nav-item
                            name="assigned"
                            icon_type="checkedout"
                            label="{{ trans('general.assigned') }}"
                            count="{{ $consumable->numCheckedOut() }}"
                    />

                    <x-tabs.files-tab :item="$consumable" count="{{ $consumable->uploads()->count() }}"/>

                    <x-tabs.upload-tab :item="$consumable"/>

                </x-slot:tabnav>

                <x-slot:tabpanes>

                    {{-- start merged activity tab pane (default) --}}
                    <x-tabs.pane name="activity" class="active in">
                        @include('consumables._activity')
                    </x-tabs.pane>
                    {{-- end merged activity tab pane --}}

                    <x-tabs.pane name="assigned">

                        <x-table
                            :presenter="\App\Presenters\ConsumablePresenter::checkedOut()"
                            :api_url="route('api.consumables.show.users', $consumable->id)"
                        />

                    </x-tabs.pane>

                    <x-tabs.pane name="files">
                        <x-table.files object_type="consumables" :object="$consumable"/>
                    </x-tabs.pane>

                </x-slot:tabpanes>

            </x-tabs>
        </x-page-column>
    </x-container>

@endsection

@section('moar_scripts')
    @can('files', $consumable)
        @include ('modals.upload-file', ['item_type' => 'consumables', 'item_id' => $consumable->id])
    @endcan

    @include ('partials.bootstrap-table', ['exportFile' => 'consumable-' . $consumable->name . '-export', 'search' => false])

    {{-- Drive the merged Activity table client-side: ingest the server-rendered
         DOM rows so we keep search / sort / pagination / column toggle / CSV
         export, and route the Type filter through bootstrap-table's filterBy so
         it composes with the rest. Deliberately NOT a .snipe-table, so snipe's
         own ajax-table init leaves it alone. --}}
    <script nonce="{{ csrf_token() }}">
        $(function () {
            var $table = $('#consumable-activity-table');
            if (!$table.length || !$.fn.bootstrapTable) { return; }

            $table.bootstrapTable({
                search: true,
                pagination: true,
                pageSize: 20,
                pageList: [10, 20, 50, 100, 'All'],
                sortName: 'when',
                sortOrder: 'desc',
                showColumns: true,
                showExport: true,
                exportDataType: 'all',
                exportTypes: ['csv'],
                exportOptions: { fileName: 'consumable-{{ \Illuminate\Support\Str::slug($consumable->name) }}-activity-' + new Date().toISOString().slice(0, 10) },
                escape: false,
                onPostBody: function () {
                    $table.closest('.tab-pane').find('[data-tooltip="true"]').tooltip();
                }
            });

            $('[data-activity-filter]').on('click', 'button[data-filter]', function () {
                var filter = this.getAttribute('data-filter');
                $('[data-activity-filter] button').removeClass('active');
                $(this).addClass('active');
                $table.bootstrapTable('filterBy', filter === 'all' ? {} : { activity_type: filter });
            });
        });
    </script>
@endsection


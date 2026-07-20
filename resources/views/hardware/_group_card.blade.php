{{--
    One field-group card for the asset detail tab. Rows are label/value flex
    pairs (no fixed-width <dt> that overlaps long values). Device Management
    Service gets a full-width one-line label. Native asset fields are injected
    into the group they belong to: Location -> Inventory (editable home
    location); Order Number / Purchase Date / Purchase Cost ->
    Procurement.

    Expects: $section (['group' => FieldGroup|null, 'fields' => []]), plus
    $asset, $snipeSettings, $locationOptions from the including scope.
--}}
@php
    $g = $section['group'];
    $headerColor = $g && $g->color ? $g->color : '#777';
    $headerIcon  = $g && $g->icon ? $g->icon : 'fas fa-layer-group';
    $headerName  = $g ? $g->name : trans('admin/custom_fields/general.other_group_label');
    $copyIcon = fn ($target) =>
        '<i class="js-copy-link far fa-copy hidden-print inline-core-copy" data-clipboard-target=".'.$target.'" data-tooltip="true" data-placement="top" title="'.trans('general.copy_to_clipboard').'" aria-hidden="true"></i>';
@endphp
<div class="box box-default asset-card">
    <div class="box-header with-border">
        <h3 class="box-title asset-card-title">
            <i class="{{ $headerIcon }}" style="color: {{ $headerColor }};" aria-hidden="true"></i>
            {{ $headerName }}
        </h3>
    </div>
    <div class="box-body asset-card-body">
        {{-- Procurement leads with the purchase facts (date + cost). --}}
        @if ($g && $g->slug === 'procurement')
            @if ($asset->purchase_date)
                <div class="asset-card-row">
                    <div class="asset-card-lbl">{{ trans('general.purchase_date') }}</div>
                    <div class="asset-card-val">
                        <span class="inline-core-value js-copy-pd-{{ $asset->id }}">{{ Helper::getFormattedDateObject($asset->purchase_date, 'date', false) }}</span>
                        {!! $copyIcon('js-copy-pd-'.$asset->id) !!}
                    </div>
                </div>
            @endif
            @if ($asset->purchase_cost)
                <div class="asset-card-row">
                    <div class="asset-card-lbl">{{ trans('general.purchase_cost') }}</div>
                    <div class="asset-card-val">
                        <span class="inline-core-value js-copy-uc-{{ $asset->id }}">{{ ($asset->location ? $asset->location->currency : $snipeSettings->default_currency) }} {{ Helper::formatCurrencyOutput($asset->purchase_cost) }}</span>
                        {!! $copyIcon('js-copy-uc-'.$asset->id) !!}
                    </div>
                </div>
            @endif
            @if ($asset->supplier)
                <div class="asset-card-row">
                    <div class="asset-card-lbl">{{ trans('general.supplier') }}</div>
                    <div class="asset-card-val"><a href="{{ route('suppliers.show', $asset->supplier->id) }}">{{ $asset->supplier->name }}</a></div>
                </div>
            @endif
            {{-- Lessor: who financed the lease (a Supplier record in the lessor role),
                 distinct from the supplier who sold the device. Surfaced here so leased
                 assets show their lessor without opening the edit form. --}}
            @if ($asset->lessor)
                <div class="asset-card-row">
                    <div class="asset-card-lbl">{{ trans('general.lessor') }}</div>
                    <div class="asset-card-val"><a href="{{ route('suppliers.show', $asset->lessor->id) }}">{{ $asset->lessor->name }}</a></div>
                </div>
            @endif

            {{-- Lease / purchasing fields, now rendered from NATIVE asset columns
                 (F2 lease migration) so they survive the _snipeit_* custom-field
                 drop. Lease-only rows show only for leased assets; PO/Invoice/
                 Warranty/Ownership always show. Filled ID/Name/Ownership values
                 deep-link to the matching filtered asset list. --}}
            @php
                $isLeaseAsset = stripos((string) ($asset->ownership_type ?? ''), 'lease') !== false;
            @endphp

            <div class="asset-card-row">
                <div class="asset-card-lbl">{{ trans('general.ownership_type') }}</div>
                <div class="asset-card-val">
                    <x-inline-core-field :asset="$asset" column="ownership_type" element="text"
                        :link="filled($asset->ownership_type) ? route('hardware.index', ['ownership_type' => $asset->ownership_type]) : null">{{ $asset->ownership_type }}</x-inline-core-field>
                </div>
            </div>

            @if ($isLeaseAsset)
                <div class="asset-card-row">
                    <div class="asset-card-lbl">{{ trans('general.lease_contract_id') }}</div>
                    <div class="asset-card-val">
                        <x-inline-core-field :asset="$asset" column="lease_contract_id" element="text"
                            :link="filled($asset->lease_contract_id) ? route('hardware.index', ['lease_contract_id' => $asset->lease_contract_id]) : null">{{ $asset->lease_contract_id }}</x-inline-core-field>
                    </div>
                </div>
                <div class="asset-card-row">
                    <div class="asset-card-lbl">{{ trans('general.lease_contract_name') }}</div>
                    <div class="asset-card-val">
                        <x-inline-core-field :asset="$asset" column="lease_contract_name" element="text"
                            :link="filled($asset->lease_contract_name) ? route('hardware.index', ['lease_contract_name' => $asset->lease_contract_name]) : null">{{ $asset->lease_contract_name }}</x-inline-core-field>
                    </div>
                </div>
                <div class="asset-card-row">
                    <div class="asset-card-lbl">{{ trans('general.lease_end_date') }}</div>
                    <div class="asset-card-val">
                        <x-inline-core-field :asset="$asset" column="lease_end_date" element="date">{{ $asset->lease_end_date ? Helper::getFormattedDateObject($asset->lease_end_date, 'date', false) : '' }}</x-inline-core-field>
                    </div>
                </div>
                <div class="asset-card-row">
                    <div class="asset-card-lbl">{{ trans('general.lease_rent') }}</div>
                    <div class="asset-card-val">
                        <x-inline-core-field :asset="$asset" column="lease_rent" element="text">{{ $asset->lease_rent }}</x-inline-core-field>
                    </div>
                </div>
                <div class="asset-card-row">
                    <div class="asset-card-lbl">{{ trans('general.buyout_cost') }}</div>
                    <div class="asset-card-val">
                        <x-inline-core-field :asset="$asset" column="buyout_cost" element="text">{{ $asset->buyout_cost }}</x-inline-core-field>
                    </div>
                </div>
            @endif

            <div class="asset-card-row">
                <div class="asset-card-lbl">{{ trans('general.po_number') }}</div>
                <div class="asset-card-val">
                    <x-inline-core-field :asset="$asset" column="po_number" element="text">{{ $asset->po_number }}</x-inline-core-field>
                </div>
            </div>
            <div class="asset-card-row">
                <div class="asset-card-lbl">{{ trans('general.invoice_number') }}</div>
                <div class="asset-card-val">
                    <x-inline-core-field :asset="$asset" column="invoice_number" element="text">{{ $asset->invoice_number }}</x-inline-core-field>
                </div>
            </div>
            <div class="asset-card-row">
                <div class="asset-card-lbl">{{ trans('general.warranty_soft_cost') }}</div>
                <div class="asset-card-val">
                    <x-inline-core-field :asset="$asset" column="warranty_soft_cost" element="text">{{ $asset->warranty_soft_cost }}</x-inline-core-field>
                </div>
            </div>
        @endif

        @foreach ($section['fields'] as $field)
            <div class="asset-card-row">
                <div class="asset-card-lbl">{{ $field->name }}</div>
                <div class="asset-card-val"><x-inline-custom-field :asset="$asset" :field="$field"/></div>
            </div>
        @endforeach

        @if ($g && $g->slug === 'inventory')
            <div class="asset-card-row">
                <div class="asset-card-lbl">{{ trans('general.location') }}</div>
                <div class="asset-card-val">
                    <x-inline-core-field :asset="$asset" column="rtd_location_id" element="select" :options="$locationOptions" copy_what="rtd-loc-{{ $asset->id }}">{!! $asset->defaultLoc?->present()->nameUrl !!}</x-inline-core-field>
                </div>
            </div>
            {{-- Usage / Area — native lease columns (F2 migration), always shown. --}}
            <div class="asset-card-row">
                <div class="asset-card-lbl">{{ trans('general.lease_usage') }}</div>
                <div class="asset-card-val">
                    <x-inline-core-field :asset="$asset" column="lease_usage" element="text">{{ $asset->lease_usage }}</x-inline-core-field>
                </div>
            </div>
            <div class="asset-card-row">
                <div class="asset-card-lbl">{{ trans('general.lease_area') }}</div>
                <div class="asset-card-val">
                    <x-inline-core-field :asset="$asset" column="lease_area" element="text">{{ $asset->lease_area }}</x-inline-core-field>
                </div>
            </div>
        @endif

        @if ($g && $g->slug === 'procurement')
            <div class="asset-card-row">
                <div class="asset-card-lbl">{{ trans('general.order_number') }}</div>
                <div class="asset-card-val"><x-inline-core-field :asset="$asset" column="order_number" copy_what="order_number_grp"/></div>
            </div>
        @endif
    </div>
</div>

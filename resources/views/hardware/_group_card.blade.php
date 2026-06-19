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
        @endif

        @if ($g && $g->slug === 'procurement')
            <div class="asset-card-row">
                <div class="asset-card-lbl">{{ trans('general.order_number') }}</div>
                <div class="asset-card-val"><x-inline-core-field :asset="$asset" column="order_number" copy_what="order_number_grp"/></div>
            </div>
        @endif
    </div>
</div>

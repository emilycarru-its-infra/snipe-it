@extends('layouts/edit-form', [
    'createText' => trans('admin/productidentities/general.create'),
    'updateText' => trans('admin/productidentities/general.update'),
    'topSubmit' => true,
    'formAction' => ($item->id) ? route('product-identities.update', ['productIdentity' => $item->id]) : route('product-identities.store'),
    'index_route' => 'product-identities.index',
])

@php
    // Saved rows plus a few blank ones to add to; after a failed save, the
    // rows exactly as they were posted.
    $aliasRows = old('aliases', $item->aliases?->map(fn ($a) => $a->only(['platform', 'match_type', 'pattern']))->all() ?? []);
    $aliasRows = array_merge(array_values($aliasRows), array_fill(0, 3, ['platform' => 'any', 'match_type' => 'prefix', 'pattern' => '']));
    $linkRows = old('license_links', $item->licenses?->map(fn ($l) => ['license_id' => $l->id, 'fiscal_year' => $l->pivot->fiscal_year])->all() ?? []);
    $linkRows = array_merge(array_values($linkRows), array_fill(0, 3, ['license_id' => '', 'fiscal_year' => '']));
@endphp

@section('inputFields')

@foreach ([
    'name' => ['general.name', true, null],
    'publisher' => ['admin/productidentities/general.publisher', false, null],
    'procurement_name' => ['admin/productidentities/general.procurement_name', false, 'admin/productidentities/general.procurement_help'],
] as $field => [$label, $required, $help])
<div class="form-group {{ $errors->has($field) ? ' has-error' : '' }}">
    <label for="{{ $field }}" class="col-md-3 control-label">{{ trans($label) }}@if ($required)<sup>*</sup>@endif</label>
    <div class="col-md-7">
        <input class="form-control" type="text" name="{{ $field }}" id="{{ $field }}" value="{{ old($field, $item->{$field}) }}" maxlength="255" @required($required)>
        @if ($help)<p class="help-block">{{ trans($help) }}</p>@endif
        {!! $errors->first($field, '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
    </div>
</div>
@endforeach

<div class="form-group {{ $errors->has('notes') ? ' has-error' : '' }}">
    <label for="notes" class="col-md-3 control-label">{{ trans('admin/productidentities/general.notes') }}</label>
    <div class="col-md-7">
        <textarea class="form-control" name="notes" id="notes" rows="3">{{ old('notes', $item->notes) }}</textarea>
    </div>
</div>

<hr>
<h4 class="col-md-offset-3">{{ trans('admin/productidentities/general.aliases') }}</h4>
<p class="col-md-offset-3 text-muted col-md-7">{{ trans('admin/productidentities/general.aliases_help') }}</p>

@foreach ($aliasRows as $i => $row)
<div class="form-group {{ $errors->has("aliases.$i.pattern") ? ' has-error' : '' }}">
    <div class="col-md-2 col-md-offset-3">
        <select class="form-control" name="aliases[{{ $i }}][platform]" aria-label="{{ trans('admin/productidentities/general.platform') }}">
            @foreach (\App\Models\ProductIdentityAlias::PLATFORMS as $platform)
                <option value="{{ $platform }}" @selected(($row['platform'] ?? 'any') === $platform)>{{ trans('admin/productidentities/general.platform_'.$platform) }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-2">
        <select class="form-control" name="aliases[{{ $i }}][match_type]" aria-label="{{ trans('admin/productidentities/general.match_type') }}">
            @foreach (\App\Models\ProductIdentityAlias::MATCH_TYPES as $type)
                <option value="{{ $type }}" @selected(($row['match_type'] ?? 'exact') === $type)>{{ trans('admin/productidentities/general.match_'.$type) }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-3">
        <input class="form-control" type="text" name="aliases[{{ $i }}][pattern]" value="{{ $row['pattern'] ?? '' }}" maxlength="255" aria-label="{{ trans('admin/productidentities/general.pattern') }}">
        {!! $errors->first("aliases.$i.pattern", '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
    </div>
</div>
@endforeach

<hr>
<h4 class="col-md-offset-3">{{ trans('admin/productidentities/general.license_links') }}</h4>
<p class="col-md-offset-3 text-muted col-md-7">{{ trans('admin/productidentities/general.license_links_help') }}</p>

@foreach ($linkRows as $i => $row)
<div class="form-group {{ ($errors->has("license_links.$i.license_id") || $errors->has("license_links.$i.fiscal_year")) ? ' has-error' : '' }}">
    <div class="col-md-4 col-md-offset-3">
        <select class="form-control" name="license_links[{{ $i }}][license_id]" aria-label="{{ trans('general.license') }}">
            <option value=""></option>
            @foreach ($licenses as $license)
                <option value="{{ $license->id }}" @selected((string) ($row['license_id'] ?? '') === (string) $license->id)>{{ $license->name }}</option>
            @endforeach
        </select>
        {!! $errors->first("license_links.$i.license_id", '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
    </div>
    <div class="col-md-3">
        <select class="form-control" name="license_links[{{ $i }}][fiscal_year]" aria-label="{{ trans('admin/productidentities/general.fiscal_year') }}">
            <option value="">{{ trans('admin/productidentities/general.every_year') }}</option>
            @foreach (array_unique(array_filter(array_merge($fiscalYears, [$row['fiscal_year'] ?? null]))) as $fy)
                <option value="{{ $fy }}" @selected(($row['fiscal_year'] ?? '') === $fy)>{{ $fy }}</option>
            @endforeach
        </select>
        {!! $errors->first("license_links.$i.fiscal_year", '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
    </div>
</div>
@endforeach

@stop

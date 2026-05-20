@extends('layouts/default')

@section('title')
    {{ trans('admin/toners/general.toners') }}
    @parent
@stop

@section('header_right')
    <a href="{{ route('consumables.index') }}" class="btn btn-sm btn-default">
        <x-icon type="consumables" class="fa-fw" />
        {{ trans('general.consumables') }}
    </a>
@stop

@section('content')
<div class="row">
    <div class="col-md-12">
        <p class="text-muted">
            {{ trans('admin/toners/general.intro') }}
            <strong>{{ $totalModels }}</strong>
            {{ trans_choice('admin/toners/general.model_count', $totalModels) }},
            <strong>{{ $totalConsumables }}</strong>
            {{ trans_choice('admin/toners/general.consumable_count', $totalConsumables) }}.
        </p>
    </div>
</div>

@forelse ($modelGroups as $manufacturerName => $models)
    <div class="row">
        <div class="col-md-12">
            <h2 style="margin-top:24px; padding-bottom:8px; border-bottom:1px solid #555;">
                {{ $manufacturerName }}
            </h2>
        </div>
    </div>
    <div class="row">
        @foreach ($models as $model)
            <div class="col-md-4 col-sm-6">
                <div class="box box-default">
                    <div class="box-header with-border">
                        <h3 class="box-title">
                            {{ $model->name }}
                            <small class="text-muted">(×{{ $model->assets_count }})</small>
                        </h3>
                    </div>
                    <div class="box-body" style="padding:0;">
                        <table class="table table-striped" style="margin-bottom:0;">
                            <tbody>
                            @foreach ($model->compatibleConsumables as $consumable)
                                @php
                                    $remaining = (int) $consumable->numRemaining();
                                    $min = (int) ($consumable->min_amt ?? 0);
                                    if ($remaining <= 0) {
                                        $cellClass = 'bg-red';
                                    } elseif ($min > 0 && $remaining <= $min) {
                                        $cellClass = 'bg-yellow';
                                    } else {
                                        $cellClass = 'bg-green';
                                    }
                                @endphp
                                <tr>
                                    <td>
                                        <a href="{{ route('consumables.show', $consumable->id) }}">
                                            {{ $consumable->name }}
                                        </a>
                                    </td>
                                    <td class="{{ $cellClass }}" style="width:60px; text-align:center; font-weight:bold;">
                                        {{ $remaining }}
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@empty
    <div class="row">
        <div class="col-md-12">
            <div class="callout callout-info">
                <i class="fa-solid fa-circle-info"></i>
                {{ trans('admin/toners/general.empty_state') }}
                <a href="{{ route('consumables.index') }}">{{ trans('general.consumables') }}</a>.
            </div>
        </div>
    </div>
@endforelse
@stop

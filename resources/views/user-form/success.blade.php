@extends('layouts/default')

@section('title')
    {{ trans('admin/user-form/general.success_title') }}
    @parent
@stop

@section('content')

<div class="row">
    <div class="col-md-8 col-md-offset-2">
        <div class="box box-success">
            <div class="box-header with-border">
                <h2 class="box-title">
                    <i class="fas fa-check-circle text-success" aria-hidden="true"></i>
                    {{ trans('admin/user-form/general.success_title') }}
                </h2>
            </div>
            <div class="box-body">
                <p>{{ trans('admin/user-form/general.success_body') }}</p>

                <div style="margin-top:20px; text-align:center;">
                    <a href="{{ $externalPurchaseUrl }}" target="_blank" rel="noopener" class="btn btn-primary btn-lg">
                        <i class="fas fa-external-link-alt" aria-hidden="true"></i>
                        {{ trans('admin/user-form/general.success_button') }}
                    </a>
                </div>

                <div style="margin-top:20px; text-align:center;">
                    <a href="{{ route('view-assets') }}" class="text-muted">
                        {{ trans('admin/user-form/general.success_back') }}
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

@stop

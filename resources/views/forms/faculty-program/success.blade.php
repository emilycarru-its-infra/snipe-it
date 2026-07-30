@extends('layouts/default')

@section('title')
    {{ trans('admin/forms/faculty-program.success_title') }}
    @parent
@stop

@section('content')

<div class="row">
    <div class="col-md-8">
        <div class="box box-success">
            <div class="box-header with-border">
                <h2 class="box-title">
                    <i class="fas fa-check-circle text-success" aria-hidden="true"></i>
                    {{ trans('admin/forms/faculty-program.success_title') }}
                </h2>
            </div>
            <div class="box-body">
                <p>{{ trans('admin/forms/faculty-program.success_body') }}</p>

                <div style="margin-top:20px; text-align:center;">
                    <a href="{{ $purchaseUrl }}" class="btn btn-primary btn-lg"
                       @if ($purchaseIsExternal) target="_blank" rel="noopener" @endif>
                        <i class="fas {{ $purchaseIsExternal ? 'fa-external-link-alt' : 'fa-cart-shopping' }}" aria-hidden="true"></i>
                        {{ trans('admin/forms/faculty-program.success_button') }}
                    </a>
                </div>

                <div style="margin-top:20px; text-align:center;">
                    <a href="{{ route('view-assets') }}" class="text-muted">
                        {{ trans('admin/forms/faculty-program.success_back') }}
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

@stop

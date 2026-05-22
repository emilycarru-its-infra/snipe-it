@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ trans('admin/consumables/general.create_transaction') }}
@parent
@stop

{{-- Page content --}}
@section('content')

<div class="row">
  <div class="col-md-8 col-md-offset-1">

    <form class="form-horizontal" method="post"
          action="{{ route('consumables.transactions.store', $consumable->id) }}" autocomplete="off">
      @csrf

      <div class="box box-default">
        <div class="box-header with-border">
          <h2 class="box-title">
            {{ trans('admin/consumables/general.create_transaction') }} — {{ $consumable->name }}
          </h2>
        </div>

        <div class="box-body">

          <div class="form-group">
            <div class="col-md-8 col-md-offset-3">
              <div class="callout callout-info" style="margin-bottom: 0;">
                {{ trans('admin/consumables/general.create_transaction_help') }}
              </div>
            </div>
          </div>

          {{-- Printer: the same compatible-model-filtered asset selector the
               checkout form uses; its gl_code pre-fills the GL field below. --}}
          @include('partials.forms.edit.asset-select', [
              'translated_name' => trans('admin/consumables/general.gl_txn_printer'),
              'fieldname' => 'asset_id',
              'model_ids' => $compatibleModelIds,
          ])

          <div class="form-group {{ $errors->has('gl_code') ? 'has-error' : '' }}">
            <label for="gl_code" class="col-md-3 control-label">{{ trans('admin/consumables/general.gl_txn_code') }}</label>
            <div class="col-md-7">
              <input class="form-control" type="text" name="gl_code" id="gl_code"
                     value="{{ old('gl_code') }}" maxlength="191"
                     placeholder="{{ trans('admin/consumables/general.gl_code_checkout_placeholder') }}">
              <p class="help-block">{{ trans('admin/consumables/general.gl_code_checkout_help') }}</p>
              {!! $errors->first('gl_code', '<span class="alert-msg"><i class="fas fa-times"></i> :message</span>') !!}
            </div>
          </div>

          <div class="form-group {{ $errors->has('transaction_date') ? 'has-error' : '' }}">
            <label for="transaction_date" class="col-md-3 control-label">{{ trans('admin/consumables/general.gl_txn_date') }}</label>
            <div class="col-md-4">
              <input class="form-control" type="date" name="transaction_date" id="transaction_date"
                     value="{{ old('transaction_date', now()->format('Y-m-d')) }}" required>
              {!! $errors->first('transaction_date', '<span class="alert-msg"><i class="fas fa-times"></i> :message</span>') !!}
            </div>
          </div>

          <div class="form-group {{ $errors->has('quantity') ? 'has-error' : '' }}">
            <label for="quantity" class="col-md-3 control-label">{{ trans('admin/consumables/general.gl_txn_qty') }}</label>
            <div class="col-md-3">
              <input class="form-control" type="number" name="quantity" id="quantity" min="1"
                     value="{{ old('quantity', 1) }}" required>
              {!! $errors->first('quantity', '<span class="alert-msg"><i class="fas fa-times"></i> :message</span>') !!}
            </div>
          </div>

          <div class="form-group {{ $errors->has('unit_cost') ? 'has-error' : '' }}">
            <label for="unit_cost" class="col-md-3 control-label">{{ trans('admin/consumables/general.gl_txn_unit_cost') }}</label>
            <div class="col-md-3">
              <input class="form-control" type="number" step="0.01" min="0" name="unit_cost" id="unit_cost"
                     value="{{ old('unit_cost', $consumable->purchase_cost) }}">
              <p class="help-block">{{ trans('admin/consumables/general.unit_cost_edit_help') }}</p>
              {!! $errors->first('unit_cost', '<span class="alert-msg"><i class="fas fa-times"></i> :message</span>') !!}
            </div>
          </div>

          <div class="form-group {{ $errors->has('status') ? 'has-error' : '' }}">
            <label for="status" class="col-md-3 control-label">{{ trans('admin/consumables/general.gl_txn_status') }}</label>
            <div class="col-md-4">
              <select class="form-control" name="status" id="status">
                @foreach ([
                    \App\Models\ConsumableTransaction::STATUS_DRAFT,
                    \App\Models\ConsumableTransaction::STATUS_POSTED,
                    \App\Models\ConsumableTransaction::STATUS_TRANSFERRED,
                ] as $status)
                  <option value="{{ $status }}" @selected(old('status', \App\Models\ConsumableTransaction::STATUS_DRAFT) === $status)>
                    {{ ucfirst($status) }}
                  </option>
                @endforeach
              </select>
              {!! $errors->first('status', '<span class="alert-msg"><i class="fas fa-times"></i> :message</span>') !!}
            </div>
          </div>

          <div class="form-group {{ $errors->has('notes') ? 'has-error' : '' }}">
            <label for="notes" class="col-md-3 control-label">{{ trans('general.notes') }}</label>
            <div class="col-md-7">
              <textarea class="form-control" name="notes" id="notes" rows="3">{{ old('notes') }}</textarea>
              {!! $errors->first('notes', '<span class="alert-msg"><i class="fas fa-times"></i> :message</span>') !!}
            </div>
          </div>

        </div>

        <div class="box-footer text-right">
          <a href="{{ route('consumables.show', $consumable->id) }}" class="btn btn-link">{{ trans('button.cancel') }}</a>
          <button type="submit" class="btn btn-primary">{{ trans('admin/consumables/general.create_transaction') }}</button>
        </div>
      </div>
    </form>

  </div>
</div>
@stop

@section('moar_scripts')
<script nonce="{{ csrf_token() }}">
    $(function () {
        // Pre-fill the GL code from the selected printer — the asset
        // selectlist carries each asset's gl_code, so no extra request.
        var glField = document.getElementById('gl_code');
        if (glField) {
            $('#assigned_asset_select').on('select2:select', function (e) {
                var data = e.params && e.params.data ? e.params.data : {};
                glField.value = data.gl_code || '';
            });
        }
    });
</script>
@stop

@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ trans('admin/consumables/general.edit_transaction') }}
@parent
@stop

{{-- Page content --}}
@section('content')

<div class="row">
  <div class="col-md-8 col-md-offset-1">

    <form class="form-horizontal" method="post"
          action="{{ route('consumables.transactions.update', [$consumable->id, $transaction->id]) }}"
          autocomplete="off">
      @csrf
      @method('PUT')

      <div class="box box-default">
        <div class="box-header with-border">
          <h2 class="box-title">
            {{ trans('admin/consumables/general.edit_transaction') }} — {{ $consumable->name }}
          </h2>
        </div>

        <div class="box-body">

          {{-- Printer is fixed at checkout time; shown for context, not editable. --}}
          <div class="form-group">
            <label class="col-md-3 control-label">{{ trans('admin/consumables/general.gl_txn_printer') }}</label>
            <div class="col-md-7">
              <p class="form-control-static">
                @if ($transaction->asset)
                  <a href="{{ route('hardware.show', $transaction->asset->id) }}">{{ $transaction->asset->present()->name() }}</a>
                @else
                  —
                @endif
              </p>
            </div>
          </div>

          <div class="form-group {{ $errors->has('gl_code') ? 'has-error' : '' }}">
            <label for="gl_code" class="col-md-3 control-label">{{ trans('admin/consumables/general.gl_txn_code') }}</label>
            <div class="col-md-7">
              <input class="form-control" type="text" name="gl_code" id="gl_code"
                     value="{{ old('gl_code', $transaction->gl_code) }}" maxlength="191">
              <p class="help-block">{{ trans('admin/consumables/general.gl_code_edit_help') }}</p>
              {!! $errors->first('gl_code', '<span class="alert-msg"><i class="fas fa-times"></i> :message</span>') !!}
            </div>
          </div>

          <div class="form-group {{ $errors->has('transaction_date') ? 'has-error' : '' }}">
            <label for="transaction_date" class="col-md-3 control-label">{{ trans('admin/consumables/general.gl_txn_date') }}</label>
            <div class="col-md-4">
              <x-input.datepicker name="transaction_date" id="transaction_date"
                                  value="{{ old('transaction_date', optional($transaction->transaction_date)->format('Y-m-d')) }}" required="1" />
              {!! $errors->first('transaction_date', '<span class="alert-msg"><i class="fas fa-times"></i> :message</span>') !!}
            </div>
          </div>

          <div class="form-group {{ $errors->has('quantity') ? 'has-error' : '' }}">
            <label for="quantity" class="col-md-3 control-label">{{ trans('admin/consumables/general.gl_txn_qty') }}</label>
            <div class="col-md-3">
              <input class="form-control" type="number" name="quantity" id="quantity" min="1"
                     value="{{ old('quantity', $transaction->quantity) }}" required>
              {!! $errors->first('quantity', '<span class="alert-msg"><i class="fas fa-times"></i> :message</span>') !!}
            </div>
          </div>

          <div class="form-group {{ $errors->has('unit_cost') ? 'has-error' : '' }}">
            <label for="unit_cost" class="col-md-3 control-label">{{ trans('admin/consumables/general.gl_txn_unit_cost') }}</label>
            <div class="col-md-3">
              <input class="form-control" type="number" step="0.01" min="0" name="unit_cost" id="unit_cost"
                     value="{{ old('unit_cost', $transaction->unit_cost) }}">
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
                  <option value="{{ $status }}" @selected(old('status', $transaction->status) === $status)>
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
              <textarea class="form-control" name="notes" id="notes" rows="3">{{ old('notes', $transaction->notes) }}</textarea>
              {!! $errors->first('notes', '<span class="alert-msg"><i class="fas fa-times"></i> :message</span>') !!}
            </div>
          </div>

        </div>

        <div class="box-footer text-right">
          <a href="{{ route('consumables.show', $consumable->id) }}" class="btn btn-link">{{ trans('button.cancel') }}</a>
          <button type="submit" class="btn btn-primary">{{ trans('general.save') }}</button>
        </div>
      </div>
    </form>

  </div>
</div>
@stop

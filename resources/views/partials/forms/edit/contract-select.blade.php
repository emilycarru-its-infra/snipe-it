<div class="form-group {{ $errors->has($fieldname) ? ' has-error' : '' }}">

    <label for="{{ $fieldname }}" class="col-md-3 control-label">
        {{ $translated_name }}
        @if (! isset($optional) || $optional !== 'true') <span class="text-danger">*</span> @endif
    </label>

    <div class="col-md-7">
        <select class="js-data-ajax" data-endpoint="contracts" data-placeholder="{{ trans('admin/contracts/general.select_contract') }}" name="{{ $fieldname }}" style="width: 100%" id="contract_select" aria-label="{{ $fieldname }}"@if (! isset($optional) || $optional !== 'true') required @endif>
            @if ($contract_id = old($fieldname, (isset($item)) ? $item->{$fieldname} : ''))
                @php $c = \App\Models\Contract::find($contract_id); @endphp
                @if ($c)
                    <option value="{{ $c->id }}" selected="selected" role="option" aria-selected="true">
                        {{ $c->name }}@if ($c->contract_number && $c->contract_number !== $c->name) ({{ $c->contract_number }}) @endif@if ($c->fiscal_year) — {{ $c->fiscal_year }} @endif
                    </option>
                @endif
            @endif
        </select>
    </div>

    {!! $errors->first($fieldname, '<div class="col-md-8 col-md-offset-3"><span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span></div>') !!}

</div>

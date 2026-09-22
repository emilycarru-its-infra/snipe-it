@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ trans('admin/hardware/swap.title') }}
    @parent
@stop

{{-- Page content --}}
@section('content')

    <div class="row">
        <div class="col-md-10 col-md-offset-1">
            <div class="box box-default">

                <div class="box-header with-border">
                    <h2 class="box-title">{{ trans('admin/hardware/swap.title') }}</h2>
                </div>

                <div class="box-body">
                    <p class="text-muted">{{ trans('admin/hardware/swap.intro') }}</p>

                    @unless ($other)
                        <form method="GET" action="{{ route('hardware.swap.create', $asset) }}" class="form-horizontal">
                            <div class="form-group">
                                <label class="col-md-3 control-label">{{ trans('general.asset') }}</label>
                                <div class="col-md-7">
                                    <p class="form-control-static">{{ $asset->present()->fullName }}</p>
                                </div>
                            </div>

                            @include ('partials.forms.edit.asset-select', [
                                'translated_name' => trans('admin/hardware/swap.pick'),
                                'fieldname' => 'with',
                                'unselect' => 'true',
                                'required' => 'true',
                                'company_id' => $asset->company_id,
                                'asset_selector_div_id' => 'swap_with',
                                'select_id' => 'swap_with_select',
                            ])

                            <div class="form-group">
                                <div class="col-md-7 col-md-offset-3">
                                    <button type="submit" class="btn btn-primary">
                                        <x-icon type="search" class="fa-fw"/> {{ trans('admin/hardware/swap.preview') }}
                                    </button>
                                </div>
                            </div>
                        </form>
                    @else
                        <div class="table-responsive">
                            <table class="table table-striped swap-preview">
                                <thead>
                                    <tr>
                                        <th rowspan="2">{{ trans('admin/hardware/swap.field') }}</th>
                                        <th colspan="2">
                                            <a href="{{ route('hardware.show', $asset) }}">{{ $asset->asset_tag }}</a>
                                            <small class="text-muted">{{ $asset->serial }}</small>
                                        </th>
                                        <th colspan="2">
                                            <a href="{{ route('hardware.show', $other) }}">{{ $other->asset_tag }}</a>
                                            <small class="text-muted">{{ $other->serial }}</small>
                                        </th>
                                    </tr>
                                    <tr>
                                        <th>{{ trans('admin/hardware/swap.now') }}</th>
                                        <th>{{ trans('admin/hardware/swap.after') }}</th>
                                        <th>{{ trans('admin/hardware/swap.now') }}</th>
                                        <th>{{ trans('admin/hardware/swap.after') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($rows as $column => $row)
                                        @php $moves = $row['asset'] !== $row['other']; @endphp
                                        <tr class="{{ $moves ? '' : 'text-muted' }}">
                                            <th scope="row">{{ $row['label'] }}</th>
                                            <td>{{ $row['asset'] ?? trans('admin/hardware/swap.empty') }}</td>
                                            <td class="{{ $moves ? 'text-bold' : '' }}">{{ $row['other'] ?? trans('admin/hardware/swap.empty') }}</td>
                                            <td>{{ $row['other'] ?? trans('admin/hardware/swap.empty') }}</td>
                                            <td class="{{ $moves ? 'text-bold' : '' }}">{{ $row['asset'] ?? trans('admin/hardware/swap.empty') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <p class="help-block">{{ trans('admin/hardware/swap.devices_follow') }}</p>

                        <form method="POST" action="{{ route('hardware.swap.store', $asset) }}" class="form-horizontal">
                            @csrf
                            <input type="hidden" name="other_asset_id" value="{{ $other->id }}">

                            <div class="form-group{{ $errors->has('note') ? ' has-error' : '' }}">
                                <label for="note" class="col-md-2 control-label">{{ trans('admin/hardware/swap.note') }}</label>
                                <div class="col-md-8">
                                    <textarea class="form-control" id="note" name="note" rows="2" maxlength="500">{{ old('note') }}</textarea>
                                    <p class="help-block">{{ trans('admin/hardware/swap.note_help') }}</p>
                                    {!! $errors->first('note', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
                                </div>
                            </div>

                            <div class="form-group">
                                <div class="col-md-8 col-md-offset-2">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-exchange-alt fa-fw" aria-hidden="true"></i> {{ trans('admin/hardware/swap.confirm') }}
                                    </button>
                                    <a href="{{ route('hardware.swap.create', $asset) }}" class="btn btn-link">{{ trans('admin/hardware/swap.change') }}</a>
                                </div>
                            </div>
                        </form>
                    @endunless
                </div>
            </div>
        </div>
    </div>

@stop

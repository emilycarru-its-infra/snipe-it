@extends('layouts/default')

@section('title')
    Manual Overrides — {{ sprintf('%04d-%02d', $year, $month) }}
    @parent
@stop

@section('content')

<form method="get" class="form-inline" style="margin-bottom:15px;">
    <label>{{ trans('admin/reports/transactions.col_period') }}:</label>
    <input type="number" name="year"  value="{{ $year }}"  class="form-control input-sm" style="width:90px"/>
    <input type="number" name="month" value="{{ $month }}" min="1" max="12" class="form-control input-sm" style="width:70px"/>
    <button class="btn btn-sm btn-primary" type="submit">Filter</button>
</form>

@if (session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">Manual Overrides — {{ sprintf('%04d-%02d', $year, $month) }}</h3>
        <p class="box-subtitle" style="margin:6px 0 0; color:#aaa; font-size:12px;">
            An override wins over the derived value when the workbook is emitted. Use these during parallel-run when the pipeline's number disagrees with the hand calculation.
        </p>
    </div>

    <div class="box-body table-responsive no-padding">
        <form method="post" action="{{ route('reports.transactions.overrides.store') }}">
            @csrf
            <input type="hidden" name="period_year"  value="{{ $year }}">
            <input type="hidden" name="period_month" value="{{ $month }}">
            <table class="table table-hover">
                <thead><tr>
                    <th>Line key</th>
                    <th class="text-right">Derived</th>
                    <th class="text-right">Override</th>
                    <th>Note</th>
                    <th></th>
                </tr></thead>
                <tbody>
                @forelse ($keys as $key)
                    @php
                        $d = $derived->get($key);
                        $o = $overrides->get($key);
                    @endphp
                    <tr>
                        <td><code>{{ $key }}</code></td>
                        <td class="text-right">
                            {{ $d ? '$' . number_format((float) $d->amount, 2) : '—' }}
                        </td>
                        <td class="text-right" style="min-width:140px;">
                            <input type="number" step="0.01" name="amounts[{{ $key }}]"
                                   value="{{ $o ? $o->amount : '' }}"
                                   placeholder="(no override)"
                                   class="form-control input-sm" style="text-align:right;"/>
                        </td>
                        <td>
                            <input type="text" name="notes[{{ $key }}]"
                                   value="{{ $o->note ?? '' }}" placeholder="why?"
                                   class="form-control input-sm"/>
                        </td>
                        <td style="white-space:nowrap;">
                            @if ($o)
                                <button form="del-{{ $o->id }}" class="btn btn-xs btn-danger" type="submit">
                                    <i class="fas fa-times"></i>
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted">
                        {{ trans('admin/reports/transactions.empty_period') }}
                    </td></tr>
                @endforelse
                </tbody>
            </table>
            <div style="padding:10px 15px; border-top:1px solid var(--box-header-bottom-border);">
                <button type="submit" class="btn btn-primary">
                    <i class="far fa-save"></i> Save overrides
                </button>
                <small class="text-muted" style="margin-left:15px;">
                    Saved overrides apply on the next pipeline run.
                </small>
            </div>
        </form>
    </div>
</div>

{{-- Hidden per-row delete forms so we get a fresh CSRF + RESTful URL each. --}}
@foreach ($overrides as $o)
    <form id="del-{{ $o->id }}" method="post" style="display:none;"
          action="{{ route('reports.transactions.overrides.delete', ['id' => $o->id]) }}">
        @csrf
        @method('DELETE')
    </form>
@endforeach

@stop

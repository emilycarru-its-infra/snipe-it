@extends('layouts/default')

@section('title')
    {{ trans('admin/store/general.queue') }}
    @parent
@stop

@section('header_right')
    <form method="get" style="display:inline-block;">
        <select name="status" class="form-control input-sm" style="display:inline-block; width:auto;" onchange="this.form.submit()">
            @foreach (array_merge($statuses, ['all']) as $status)
                <option value="{{ $status }}" {{ $selectedStatus === $status ? 'selected' : '' }}>{{ ucfirst($status) }}</option>
            @endforeach
        </select>
    </form>
    <a href="{{ route('procurement.index') }}" class="btn btn-sm btn-default">{{ trans('admin/store/general.procurement') }}</a>
@stop

@section('content')

<p class="text-muted">{{ trans('admin/store/general.queue_intro') }}</p>

@if ($orders->isEmpty())
    <div class="box box-default"><div class="box-body">
        <p class="text-muted">{{ trans('admin/store/general.queue_empty') }}</p>
    </div></div>
@else
    {{-- Pull-into-requisition wraps the whole list so approved orders can
         be checkbox-selected across page sections. --}}
    <form method="POST" action="{{ route('procurement.queue.pull') }}" id="pq-pull-form">
        {{ csrf_field() }}

        @if ($selectedStatus === 'approved')
            <div class="box box-primary">
                <div class="box-body form-inline">
                    <input type="text" name="title" class="form-control" style="min-width:320px;" required
                           placeholder="{{ trans('admin/purchase-orders/general.builder_title_placeholder') }}">
                    <button type="submit" class="btn btn-primary">{{ trans('admin/store/general.queue_pull_selected') }}</button>
                </div>
            </div>
        @endif

        @foreach ($orders as $order)
            <div class="box box-default">
                <div class="box-header with-border">
                    <h3 class="box-title">
                        @if ($selectedStatus === 'approved')
                            <input type="checkbox" name="orders[]" value="{{ $order->id }}" style="margin-right:8px;">
                        @endif
                        {{ trans('admin/store/general.queue_requested_by') }}
                        <strong>{{ $order->user?->present()->fullName ?: trans('general.na') }}</strong>
                        <span class="text-muted" style="font-weight:400; margin-left:6px;">{{ $order->created_at->diffForHumans() }}</span>
                        <span class="label label-default" style="margin-left:6px;">{{ ucfirst($order->status) }}</span>
                        @if ($order->isFacultyProgram())
                            <span class="label label-primary" style="margin-left:4px;">{{ trans('admin/store/general.faculty_program') }}</span>
                        @endif
                        @if ($order->vendor_sent_at)
                            <span class="label label-success" style="margin-left:4px;">{{ trans('admin/store/general.vendor_sent', ['when' => $order->vendor_sent_at->format('M j')]) }}</span>
                        @endif
                    </h3>
                    @if ($order->requisition)
                        <div class="box-tools pull-right">
                            <a href="{{ route('requisitions.show', $order->requisition_id) }}" class="btn btn-xs btn-default">
                                {{ $order->requisition->display_name }}
                            </a>
                        </div>
                    @endif
                </div>
                <div class="box-body">
                    <div class="row">
                        <div class="col-md-7">
                            <table class="table table-condensed" style="margin-bottom:0;">
                                <tbody>
                                    @foreach ($order->items as $line)
                                        <tr>
                                            <td>{{ $line->description }}</td>
                                            <td style="white-space:nowrap;">&times;{{ $line->quantity }}</td>
                                            <td class="text-right" style="white-space:nowrap;">{{ \App\Helpers\Helper::formatCurrencyOutput($line->lineTotal()) }}</td>
                                        </tr>
                                    @endforeach
                                    <tr>
                                        <td colspan="2" class="text-right"><strong>{{ trans('admin/purchase-orders/general.builder_total') }}</strong></td>
                                        <td class="text-right"><strong>{{ \App\Helpers\Helper::formatCurrencyOutput($order->total()) }}</strong></td>
                                    </tr>
                                </tbody>
                            </table>
                            @if ($order->notes)
                                <p class="text-muted" style="margin:8px 0 0;"><em>{{ $order->notes }}</em></p>
                            @endif
                        </div>
                        <div class="col-md-5">
                            @if ($order->status === 'pending')
                                {{-- Decision form posts outside the pull form via the form attribute. --}}
                                <textarea class="form-control" rows="2" form="pq-decide-{{ $order->id }}" name="decision_notes"
                                          placeholder="{{ trans('admin/store/general.queue_decision_note') }}"></textarea>
                                <div style="margin-top:8px;">
                                    <button type="submit" form="pq-decide-{{ $order->id }}" name="decision" value="approved" class="btn btn-success">
                                        {{ trans('admin/store/general.queue_approve') }}
                                    </button>
                                    <button type="submit" form="pq-decide-{{ $order->id }}" name="decision" value="declined" class="btn btn-danger">
                                        {{ trans('admin/store/general.queue_decline') }}
                                    </button>
                                </div>
                            @elseif ($order->decided_at)
                                <p class="text-muted">
                                    {{ $order->decidedBy?->present()->fullName }} · {{ $order->decided_at->format('M j, Y H:i') }}
                                    @if ($order->decision_notes)<br><em>{{ $order->decision_notes }}</em>@endif
                                </p>
                            @endif

                            @if ($order->status === 'approved')
                                <div style="margin-top:8px;">
                                    <button type="submit" form="pq-vendor-{{ $order->id }}" class="btn btn-primary">
                                        <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
                                        {{ trans('admin/store/general.vendor_send_button', ['supplier' => $order->supplier()?->name ?: 'vendor']) }}
                                    </button>
                                    <button type="submit" form="pq-vendor-test-{{ $order->id }}" class="btn btn-default">
                                        {{ trans('admin/store/general.vendor_send_test_button') }}
                                    </button>
                                </div>
                            @endif

                            @if ($order->isFacultyProgram() && in_array($order->status, ['approved', 'ordered'], true))
                                <p style="margin-top:8px;">
                                    <a href="{{ route('user-agreements.create', ['user_id' => $order->user_id]) }}" class="btn btn-xs btn-default">
                                        {{ trans('admin/store/general.faculty_intake_link') }}
                                    </a>
                                </p>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </form>

    {{-- One decision form per pending order (and vendor-send forms per
         approved order), outside the pull form so nothing ever nests. --}}
    @foreach ($orders as $order)
        @if ($order->status === 'pending')
            <form method="POST" action="{{ route('procurement.queue.decide', $order->id) }}" id="pq-decide-{{ $order->id }}">
                {{ csrf_field() }}
            </form>
        @endif
        @if ($order->status === 'approved')
            <form method="POST" action="{{ route('procurement.queue.send-vendor', $order->id) }}" id="pq-vendor-{{ $order->id }}">
                {{ csrf_field() }}
            </form>
            <form method="POST" action="{{ route('procurement.queue.send-vendor', $order->id) }}" id="pq-vendor-test-{{ $order->id }}">
                {{ csrf_field() }}
                <input type="hidden" name="test" value="1">
            </form>
        @endif
    @endforeach

    {{ $orders->links() }}
@endif
@stop

@extends('layouts/default')
@section('title', trans('admin/purchase-orders/general.okp_acceptance_title'))
@section('content')
<div class="box box-default">
    <div class="box-header"><h2 class="box-title">{{ trans('admin/purchase-orders/general.okp_acceptance_title') }} — {{ $invoice->invoice_number }}</h2></div>
    <div class="box-body">
        <p class="alert alert-warning">{{ trans('admin/purchase-orders/general.okp_acceptance_warning') }}</p>
        <p>{{ $invoice->order?->order_number }} · {{ $invoice->order?->lease_schedule }} · {{ $invoice->invoice_date?->format('Y-m-d') }} · {{ \App\Services\Leasing\OkayToPay::money($invoice->total) }}</p>
        @foreach ($reasons as $reason)<p class="text-danger">{{ $reason }}</p>@endforeach
        @foreach ($errors->all() as $error)<p class="text-danger">{{ $error }}</p>@endforeach
        <div class="table-responsive"><table class="table table-striped">
            <thead><tr><th>{{ trans('admin/orders/general.item') }}</th><th>{{ trans('admin/hardware/form.serial') }}</th><th>{{ trans('general.qty') }}</th><th>{{ trans('general.total_cost') }}</th></tr></thead>
            <tbody>@foreach ($invoice->items as $line)<tr>
                <td>{{ $line->description }}</td><td>{{ $line->item instanceof \App\Models\Asset ? $line->item->serial : '—' }}</td><td>{{ $line->quantity }}</td><td>{{ \App\Services\Leasing\OkayToPay::money($line->lineTotal()) }}</td>
            </tr>@endforeach</tbody>
        </table></div>
        @if ($delivery)
            <p class="alert alert-info">{{ trans('admin/purchase-orders/general.okp_delivery_record', ['status' => $delivery->status, 'time' => $delivery->claimed_at]) }}</p>
        @elseif ($invoice->okp_status !== \App\Services\Leasing\OkayToPay::SENT)
            <form method="post" action="{{ route('reports.procurement.invoice-acceptance.store', $invoice) }}">
                @csrf
                <input type="hidden" name="fingerprint" value="{{ $fingerprint }}">
                <input type="hidden" name="expected_acceptance_id" value="{{ $acceptance?->id }}">
                <div class="checkbox"><label><input type="checkbox" name="received_in_full" value="1"> {{ trans('admin/purchase-orders/general.okp_received_in_full') }}</label></div>
                <div class="form-group"><label for="receipt_reference">{{ trans('admin/purchase-orders/general.okp_receipt_reference') }}</label><input id="receipt_reference" name="receipt_reference" class="form-control" maxlength="1000" value="{{ old('receipt_reference') }}"></div>
                <div class="checkbox"><label><input type="checkbox" name="satisfactory" value="1"> {{ trans('admin/purchase-orders/general.okp_satisfactory') }}</label></div>
                <div class="form-group"><label for="acceptance_reference">{{ trans('admin/purchase-orders/general.okp_acceptance_reference') }}</label><input id="acceptance_reference" name="acceptance_reference" class="form-control" maxlength="1000" value="{{ old('acceptance_reference') }}"></div>
                <button type="submit" name="decision" value="accept" class="btn btn-primary">{{ trans('admin/purchase-orders/general.okp_record_acceptance') }}</button>
                @if ($acceptance?->decision === 'accept')<button type="submit" name="decision" value="revoke" class="btn btn-danger">{{ trans('admin/purchase-orders/general.okp_revoke_acceptance') }}</button>@endif
            </form>
        @endif
        <h3>{{ trans('general.history') }}</h3>
        @foreach ($history as $entry)<p>{{ $entry->created_at }} · {{ $entry->actor_name ?: trans('admin/purchase-orders/general.okp_former_operator') }} · {{ $entry->decision }} · {{ $entry->receipt_reference }} · {{ $entry->acceptance_reference }}</p>@endforeach
        <a href="{{ route('reports.procurement.invoice-approval') }}">{{ trans('admin/purchase-orders/general.pipeline_open_invoice_queue') }}</a>
    </div>
</div>
@stop

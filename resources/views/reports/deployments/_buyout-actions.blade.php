{{-- The three things that move a buyout along, side by side in its expanded
     row: record what the lessor quoted, set the split, advance the stage.
     Needs $row from the buyouts table. --}}
<div class="row" style="padding:10px 4px;">

    {{-- Record a quote. Supersedes the live figure, keeps the old one. --}}
    <div class="col-md-4">
        <form method="POST" action="{{ route('buyouts.quote', $row['id']) }}">
            @csrf
            <h6 style="font-weight:700; margin:0 0 8px;">{{ trans('admin/deployments/general.buyout_record_quote') }}</h6>
            <div class="form-group">
                <label for="quote_amount_{{ $row['id'] }}" style="font-weight:normal; font-size:12px;">{{ trans('admin/deployments/general.buyout_quote_amount') }}</label>
                <input type="number" step="0.01" min="0" class="form-control input-sm" id="quote_amount_{{ $row['id'] }}" name="quote_amount">
            </div>
            <div class="form-group">
                <label for="remaining_rent_{{ $row['id'] }}" style="font-weight:normal; font-size:12px;">{{ trans('admin/deployments/general.buyout_remaining_rent') }}</label>
                <input type="number" step="0.01" min="0" class="form-control input-sm" id="remaining_rent_{{ $row['id'] }}" name="remaining_rent">
            </div>
            <div class="form-group">
                <label for="quoted_at_{{ $row['id'] }}" style="font-weight:normal; font-size:12px;">{{ trans('admin/deployments/general.buyout_quoted_at') }}</label>
                <input type="date" class="form-control input-sm" id="quoted_at_{{ $row['id'] }}" name="quoted_at">
            </div>
            <div class="form-group">
                <label for="reference_{{ $row['id'] }}" style="font-weight:normal; font-size:12px;">{{ trans('admin/deployments/general.buyout_reference') }}</label>
                <input type="text" class="form-control input-sm" id="reference_{{ $row['id'] }}" name="reference">
            </div>
            <button type="submit" class="btn btn-sm btn-primary">{{ trans('admin/deployments/general.buyout_record_quote') }}</button>
        </form>
    </div>

    {{-- The split and the notes: corrected rather than advanced, so editable
         at any stage including after completion. --}}
    <div class="col-md-4">
        <form method="POST" action="{{ route('buyouts.update', $row['id']) }}">
            @csrf
            @method('PATCH')
            <h6 style="font-weight:700; margin:0 0 8px;">{{ trans('admin/deployments/general.buyout_col_split') }}</h6>
            <div class="form-group">
                <label for="buyer_amount_{{ $row['id'] }}" style="font-weight:normal; font-size:12px;">{{ trans('admin/deployments/general.buyout_buyer_amount') }}</label>
                <input type="number" step="0.01" min="0" class="form-control input-sm" id="buyer_amount_{{ $row['id'] }}" name="buyer_amount" value="{{ $row['buyer_amount'] }}">
            </div>
            <div class="form-group">
                <label for="ecu_amount_{{ $row['id'] }}" style="font-weight:normal; font-size:12px;">{{ trans('admin/deployments/general.buyout_ecu_amount') }}</label>
                <input type="number" step="0.01" min="0" class="form-control input-sm" id="ecu_amount_{{ $row['id'] }}" name="ecu_amount" value="{{ $row['ecu_amount'] }}">
            </div>
            <p class="text-muted" style="font-size:11.5px;">{{ trans('admin/deployments/general.buyout_split_hint') }}</p>
            <div class="form-group">
                <label for="notes_{{ $row['id'] }}" style="font-weight:normal; font-size:12px;">{{ trans('admin/deployments/general.buyout_notes') }}</label>
                <textarea class="form-control input-sm" id="notes_{{ $row['id'] }}" name="notes" rows="2"></textarea>
            </div>
            <button type="submit" class="btn btn-sm btn-default">{{ trans('admin/deployments/general.buyout_save') }}</button>
        </form>
    </div>

    {{-- Advance the stage. Only the moves that make sense from here. --}}
    <div class="col-md-4">
        <h6 style="font-weight:700; margin:0 0 8px;">{{ trans('admin/deployments/general.buyout_col_status') }}</h6>

        @if (in_array($row['status'], ['quoted', 'requested'], true))
            <form method="POST" action="{{ route('buyouts.transition', $row['id']) }}" style="margin-bottom:10px;">
                @csrf
                <input type="hidden" name="status" value="approved">
                <button type="submit" class="btn btn-sm btn-primary">{{ trans('admin/deployments/general.buyout_approve') }}</button>
            </form>
        @endif

        @if ($row['status'] === 'approved')
            <form method="POST" action="{{ route('buyouts.transition', $row['id']) }}" style="margin-bottom:10px;">
                @csrf
                <input type="hidden" name="status" value="invoiced">
                <div class="form-group">
                    <label for="invoice_number_{{ $row['id'] }}" style="font-weight:normal; font-size:12px;">{{ trans('admin/deployments/general.buyout_invoice_number') }}</label>
                    <input type="text" class="form-control input-sm" id="invoice_number_{{ $row['id'] }}" name="invoice_number">
                </div>
                <div class="form-group">
                    <label for="invoice_due_{{ $row['id'] }}" style="font-weight:normal; font-size:12px;">{{ trans('admin/deployments/general.buyout_invoice_due_date') }}</label>
                    <input type="date" class="form-control input-sm" id="invoice_due_{{ $row['id'] }}" name="invoice_due_date">
                </div>
                <button type="submit" class="btn btn-sm btn-primary">{{ trans('admin/deployments/general.buyout_invoice') }}</button>
            </form>
        @endif

        @if ($row['status'] === 'invoiced')
            <form method="POST" action="{{ route('buyouts.transition', $row['id']) }}" style="margin-bottom:10px;">
                @csrf
                <input type="hidden" name="status" value="paid">
                <div class="form-group">
                    <label for="payment_method_{{ $row['id'] }}" style="font-weight:normal; font-size:12px;">{{ trans('admin/deployments/general.buyout_payment_method') }}</label>
                    <select class="form-control input-sm" id="payment_method_{{ $row['id'] }}" name="payment_method">
                        @foreach (\App\Models\AssetBuyout::PAYMENT_METHODS as $method)
                            <option value="{{ $method }}">{{ trans('admin/deployments/general.buyout_method_'.$method) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label for="payment_reference_{{ $row['id'] }}" style="font-weight:normal; font-size:12px;">{{ trans('admin/deployments/general.buyout_payment_reference') }}</label>
                    <input type="text" class="form-control input-sm" id="payment_reference_{{ $row['id'] }}" name="payment_reference">
                </div>
                <button type="submit" class="btn btn-sm btn-primary">{{ trans('admin/deployments/general.buyout_pay') }}</button>
            </form>
        @endif

        @if ($row['status'] === 'paid')
            <form method="POST" action="{{ route('buyouts.transition', $row['id']) }}" style="margin-bottom:10px;"
                  onsubmit="return confirm(@js(trans('admin/deployments/general.buyout_complete_hint', ['status' => config('leasing.buyout_completed_status')])));">
                @csrf
                <input type="hidden" name="status" value="completed">
                <button type="submit" class="btn btn-sm btn-success">{{ trans('admin/deployments/general.buyout_complete') }}</button>
                <p class="text-muted" style="font-size:11.5px; margin-top:6px;">
                    {{ trans('admin/deployments/general.buyout_complete_hint', ['status' => config('leasing.buyout_completed_status')]) }}
                </p>
            </form>
        @endif

        @if ($row['open'])
            <form method="POST" action="{{ route('buyouts.transition', $row['id']) }}">
                @csrf
                <input type="hidden" name="status" value="declined">
                <div class="form-group">
                    <label for="decline_reason_{{ $row['id'] }}" style="font-weight:normal; font-size:12px;">{{ trans('admin/deployments/general.buyout_decline_reason') }}</label>
                    <input type="text" class="form-control input-sm" id="decline_reason_{{ $row['id'] }}" name="decline_reason">
                </div>
                <button type="submit" class="btn btn-sm btn-default">{{ trans('admin/deployments/general.buyout_decline') }}</button>
            </form>
        @endif
    </div>
</div>

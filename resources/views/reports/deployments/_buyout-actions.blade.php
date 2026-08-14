{{-- The expanded buyout row: the whole record, editable.

     Everything the row carries is typed in here rather than derived, because
     none of it can be derived — the lessor's price, the split between what
     the buyer pays and what ECU absorbs, and even which unit the buyout is
     against are all facts that arrive by mail, out of order, and get
     corrected afterwards. So: one form over every column, plus the two
     things that are events rather than values — a new quote, which
     supersedes without erasing, and a stage move, which stamps its own
     timestamp and (on completion) releases the device.

     Needs $row from the buyouts table. --}}
@php
    $rid = $row['id'];
    $lbl = 'font-weight:normal; font-size:12px; margin-bottom:2px;';
@endphp

{{-- 16px of side padding, not 4: the grid rows inside carry Bootstrap's
     -15px gutter margins, and anything less lets them bleed past the cell. --}}
<div style="padding:14px 16px 6px;">

    {{-- The record itself. One save, every column. --}}
    <form method="POST" action="{{ route('buyouts.update', $rid) }}">
        @csrf
        @method('PATCH')
        <div class="row">

            <div class="col-md-4">
                <h6 style="font-weight:700; margin:0 0 8px;">{{ trans('admin/deployments/general.buyout_group_record') }}</h6>

                <div class="form-group">
                    <label for="asset_id_{{ $rid }}" style="{{ $lbl }}">{{ trans('admin/deployments/general.buyout_col_asset') }}</label>
                    <select class="js-data-ajax" data-endpoint="hardware" name="asset_id" id="asset_id_{{ $rid }}" style="width:100%;"
                            data-placeholder="{{ trans('admin/deployments/general.buyout_asset_placeholder') }}">
                        @if ($row['asset_id'])
                            <option value="{{ $row['asset_id'] }}" selected>{{ trim(($row['asset_tag'] ?: $row['serial']).' '.$row['model']) }}</option>
                        @endif
                    </select>
                </div>

                <div class="form-group">
                    <label for="buyer_id_{{ $rid }}" style="{{ $lbl }}">{{ trans('admin/deployments/general.buyout_col_buyer') }}</label>
                    <select class="js-data-ajax" data-endpoint="users" name="buyer_id" id="buyer_id_{{ $rid }}" style="width:100%;">
                        @if ($row['buyer_id'])
                            <option value="{{ $row['buyer_id'] }}" selected>{{ $row['buyer'] }}</option>
                        @endif
                    </select>
                </div>

                <div class="form-group">
                    <label for="lessor_id_{{ $rid }}" style="{{ $lbl }}">{{ trans('admin/deployments/general.buyout_col_lessor') }}</label>
                    <select class="js-data-ajax" data-endpoint="suppliers" name="lessor_id" id="lessor_id_{{ $rid }}" style="width:100%;">
                        @if ($row['lessor_id'])
                            <option value="{{ $row['lessor_id'] }}" selected>{{ $row['lessor'] }}</option>
                        @endif
                    </select>
                </div>

                <div class="form-group">
                    <label for="status_{{ $rid }}" style="{{ $lbl }}">{{ trans('admin/deployments/general.buyout_col_status') }}</label>
                    <select class="form-control input-sm" name="status" id="status_{{ $rid }}">
                        @foreach (\App\Models\AssetBuyout::STATUSES as $status)
                            <option value="{{ $status }}" @selected($row['status'] === $status)>{{ trans('admin/deployments/general.buyout_status_'.$status) }}</option>
                        @endforeach
                    </select>
                    <p class="text-muted" style="font-size:11.5px; margin:4px 0 0;">{{ trans('admin/deployments/general.buyout_stage_direct_hint') }}</p>
                </div>
            </div>

            <div class="col-md-4">
                <h6 style="font-weight:700; margin:0 0 8px;">{{ trans('admin/deployments/general.buyout_group_money') }}</h6>

                <div class="form-group">
                    <label for="quote_amount_live_{{ $rid }}" style="{{ $lbl }}">{{ trans('admin/deployments/general.buyout_quote_amount') }}</label>
                    <input type="number" step="0.01" min="0" class="form-control input-sm" id="quote_amount_live_{{ $rid }}" name="quote_amount" value="{{ $row['quote_amount'] }}">
                </div>

                <div class="form-group">
                    <label for="remaining_rent_live_{{ $rid }}" style="{{ $lbl }}">{{ trans('admin/deployments/general.buyout_remaining_rent') }}</label>
                    <input type="number" step="0.01" min="0" class="form-control input-sm" id="remaining_rent_live_{{ $rid }}" name="remaining_rent" value="{{ $row['remaining_rent'] }}">
                </div>

                <div class="form-group">
                    <label for="quoted_at_live_{{ $rid }}" style="{{ $lbl }}">{{ trans('admin/deployments/general.buyout_quoted_at') }}</label>
                    <input type="date" class="form-control input-sm" id="quoted_at_live_{{ $rid }}" name="quoted_at" value="{{ $row['quoted_at'] }}">
                </div>

                <div class="form-group">
                    <label for="buyer_amount_{{ $rid }}" style="{{ $lbl }}">{{ trans('admin/deployments/general.buyout_buyer_amount') }}</label>
                    <input type="number" step="0.01" min="0" class="form-control input-sm" id="buyer_amount_{{ $rid }}" name="buyer_amount" value="{{ $row['buyer_amount'] }}">
                </div>

                <div class="form-group">
                    <label for="ecu_amount_{{ $rid }}" style="{{ $lbl }}">{{ trans('admin/deployments/general.buyout_ecu_amount') }}</label>
                    <input type="number" step="0.01" min="0" class="form-control input-sm" id="ecu_amount_{{ $rid }}" name="ecu_amount" value="{{ $row['ecu_amount'] }}">
                    <p class="text-muted" style="font-size:11.5px; margin:4px 0 0;">{{ trans('admin/deployments/general.buyout_split_hint') }}</p>
                </div>
            </div>

            <div class="col-md-4">
                <h6 style="font-weight:700; margin:0 0 8px;">{{ trans('admin/deployments/general.buyout_group_settlement') }}</h6>

                <div class="form-group">
                    <label for="invoice_number_live_{{ $rid }}" style="{{ $lbl }}">{{ trans('admin/deployments/general.buyout_invoice_number') }}</label>
                    <input type="text" class="form-control input-sm" id="invoice_number_live_{{ $rid }}" name="invoice_number" value="{{ $row['invoice_number'] }}">
                </div>

                <div class="form-group">
                    <label for="invoice_date_live_{{ $rid }}" style="{{ $lbl }}">{{ trans('admin/deployments/general.buyout_invoice_date') }}</label>
                    <input type="date" class="form-control input-sm" id="invoice_date_live_{{ $rid }}" name="invoice_date" value="{{ $row['invoice_date'] }}">
                </div>

                <div class="form-group">
                    <label for="invoice_due_live_{{ $rid }}" style="{{ $lbl }}">{{ trans('admin/deployments/general.buyout_invoice_due_date') }}</label>
                    <input type="date" class="form-control input-sm" id="invoice_due_live_{{ $rid }}" name="invoice_due_date" value="{{ $row['invoice_due_date'] }}">
                </div>

                <div class="form-group">
                    <label for="paid_at_live_{{ $rid }}" style="{{ $lbl }}">{{ trans('admin/deployments/general.buyout_paid_at') }}</label>
                    <input type="date" class="form-control input-sm" id="paid_at_live_{{ $rid }}" name="paid_at" value="{{ $row['paid_at'] }}">
                </div>

                <div class="form-group">
                    <label for="payment_method_live_{{ $rid }}" style="{{ $lbl }}">{{ trans('admin/deployments/general.buyout_payment_method') }}</label>
                    <select class="form-control input-sm" id="payment_method_live_{{ $rid }}" name="payment_method">
                        <option value="">—</option>
                        @foreach (\App\Models\AssetBuyout::PAYMENT_METHODS as $method)
                            <option value="{{ $method }}" @selected($row['payment_method'] === $method)>{{ trans('admin/deployments/general.buyout_method_'.$method) }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="form-group">
                    <label for="payment_reference_live_{{ $rid }}" style="{{ $lbl }}">{{ trans('admin/deployments/general.buyout_payment_reference') }}</label>
                    <input type="text" class="form-control input-sm" id="payment_reference_live_{{ $rid }}" name="payment_reference" value="{{ $row['payment_reference'] }}">
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-8">
                <div class="form-group">
                    <label for="notes_{{ $rid }}" style="{{ $lbl }}">{{ trans('admin/deployments/general.buyout_notes') }}</label>
                    <textarea class="form-control input-sm" id="notes_{{ $rid }}" name="notes" rows="2">{{ $row['notes'] }}</textarea>
                </div>
            </div>
            <div class="col-md-4">
                <div class="form-group">
                    <label for="decline_reason_live_{{ $rid }}" style="{{ $lbl }}">{{ trans('admin/deployments/general.buyout_decline_reason') }}</label>
                    <input type="text" class="form-control input-sm" id="decline_reason_live_{{ $rid }}" name="decline_reason" value="{{ $row['decline_reason'] }}">
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-sm btn-primary">{{ trans('admin/deployments/general.buyout_save') }}</button>
    </form>

    <hr style="margin:14px 0 12px;">

    <div class="row">

        {{-- A new quote is an event, not an edit: it supersedes the live
             figure and keeps the old one, because the superseded number is
             what people keep quoting back from the mail thread. --}}
        <div class="col-md-5">
            <form method="POST" action="{{ route('buyouts.quote', $rid) }}" class="form-inline">
                @csrf
                <h6 style="font-weight:700; margin:0 0 8px;">{{ trans('admin/deployments/general.buyout_record_quote') }}</h6>
                <div class="form-group" style="margin:0 6px 6px 0;">
                    <input type="number" step="0.01" min="0" class="form-control input-sm" name="quote_amount" style="width:120px;"
                           placeholder="{{ trans('admin/deployments/general.buyout_quote_amount') }}" aria-label="{{ trans('admin/deployments/general.buyout_quote_amount') }}">
                </div>
                <div class="form-group" style="margin:0 6px 6px 0;">
                    <input type="number" step="0.01" min="0" class="form-control input-sm" name="remaining_rent" style="width:120px;"
                           placeholder="{{ trans('admin/deployments/general.buyout_remaining_rent') }}" aria-label="{{ trans('admin/deployments/general.buyout_remaining_rent') }}">
                </div>
                <div class="form-group" style="margin:0 6px 6px 0;">
                    <input type="date" class="form-control input-sm" name="quoted_at" aria-label="{{ trans('admin/deployments/general.buyout_quoted_at') }}">
                </div>
                <div class="form-group" style="margin:0 6px 6px 0;">
                    <input type="text" class="form-control input-sm" name="reference" style="width:120px;"
                           placeholder="{{ trans('admin/deployments/general.buyout_reference') }}" aria-label="{{ trans('admin/deployments/general.buyout_reference') }}">
                </div>
                <button type="submit" class="btn btn-sm btn-default" style="margin-bottom:6px;">{{ trans('admin/deployments/general.buyout_record_quote') }}</button>
            </form>
        </div>

        {{-- Stage moves that stamp their own timestamp. The stage select above
             corrects the record; these advance it. --}}
        <div class="col-md-7">
            <h6 style="font-weight:700; margin:0 0 8px;">{{ trans('admin/deployments/general.buyout_group_advance') }}</h6>

            <div style="display:flex; flex-wrap:wrap; gap:8px; align-items:flex-start;">
                @if (in_array($row['status'], ['quoted', 'requested'], true))
                    <form method="POST" action="{{ route('buyouts.transition', $rid) }}">
                        @csrf
                        <input type="hidden" name="status" value="approved">
                        <button type="submit" class="btn btn-sm btn-primary">{{ trans('admin/deployments/general.buyout_approve') }}</button>
                    </form>
                @endif

                @if ($row['status'] === 'approved')
                    <form method="POST" action="{{ route('buyouts.transition', $rid) }}" class="form-inline">
                        @csrf
                        <input type="hidden" name="status" value="invoiced">
                        <input type="text" class="form-control input-sm" name="invoice_number" style="width:130px;"
                               placeholder="{{ trans('admin/deployments/general.buyout_invoice_number') }}" aria-label="{{ trans('admin/deployments/general.buyout_invoice_number') }}">
                        <input type="date" class="form-control input-sm" name="invoice_due_date" aria-label="{{ trans('admin/deployments/general.buyout_invoice_due_date') }}">
                        <button type="submit" class="btn btn-sm btn-primary">{{ trans('admin/deployments/general.buyout_invoice') }}</button>
                    </form>
                @endif

                @if ($row['status'] === 'invoiced')
                    <form method="POST" action="{{ route('buyouts.transition', $rid) }}" class="form-inline">
                        @csrf
                        <input type="hidden" name="status" value="paid">
                        <select class="form-control input-sm" name="payment_method" aria-label="{{ trans('admin/deployments/general.buyout_payment_method') }}">
                            @foreach (\App\Models\AssetBuyout::PAYMENT_METHODS as $method)
                                <option value="{{ $method }}">{{ trans('admin/deployments/general.buyout_method_'.$method) }}</option>
                            @endforeach
                        </select>
                        <input type="text" class="form-control input-sm" name="payment_reference" style="width:150px;"
                               placeholder="{{ trans('admin/deployments/general.buyout_payment_reference') }}" aria-label="{{ trans('admin/deployments/general.buyout_payment_reference') }}">
                        <button type="submit" class="btn btn-sm btn-primary">{{ trans('admin/deployments/general.buyout_pay') }}</button>
                    </form>
                @endif

                @if ($row['status'] === 'paid')
                    <form method="POST" action="{{ route('buyouts.transition', $rid) }}"
                          onsubmit="return confirm(@js(trans('admin/deployments/general.buyout_complete_hint', ['status' => config('leasing.buyout_completed_status')])));">
                        @csrf
                        <input type="hidden" name="status" value="completed">
                        <button type="submit" class="btn btn-sm btn-success">{{ trans('admin/deployments/general.buyout_complete') }}</button>
                    </form>
                @endif

                @if ($row['open'])
                    <form method="POST" action="{{ route('buyouts.transition', $rid) }}" class="form-inline">
                        @csrf
                        <input type="hidden" name="status" value="declined">
                        <input type="text" class="form-control input-sm" name="decline_reason" style="width:150px;"
                               placeholder="{{ trans('admin/deployments/general.buyout_decline_reason') }}" aria-label="{{ trans('admin/deployments/general.buyout_decline_reason') }}">
                        <button type="submit" class="btn btn-sm btn-default">{{ trans('admin/deployments/general.buyout_decline') }}</button>
                    </form>
                @endif
            </div>

            @if ($row['status'] === 'paid')
                <p class="text-muted" style="font-size:11.5px; margin-top:6px;">
                    {{ trans('admin/deployments/general.buyout_complete_hint', ['status' => config('leasing.buyout_completed_status')]) }}
                </p>
            @endif
        </div>
    </div>
</div>

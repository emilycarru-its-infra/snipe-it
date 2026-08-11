@extends('layouts/default')

@section('title')
    {{ $requisition->display_name }}
    @parent
@stop

@section('header_right')
    @if ($requisition->status === 'draft')
        <a href="{{ route('purchase-orders.builder', ['requisition' => $requisition->id]) }}" class="btn btn-sm btn-primary">
            {{ trans('admin/purchase-orders/general.requisition_open_builder') }}
        </a>
    @endif
    <a href="{{ route('requisitions.print', $requisition->id) }}" class="btn btn-sm btn-default" target="_blank" rel="noopener">
        {{ trans('admin/purchase-orders/general.requisition_print') }}
    </a>
    <a href="{{ route('requisitions.export', $requisition->id) }}" class="btn btn-sm btn-default">
        <x-icon type="download" /> {{ trans('general.download') }}
    </a>
    <a href="{{ route('requisitions.index') }}" class="btn btn-sm btn-default">
        {{ trans('admin/purchase-orders/general.requisitions') }}
    </a>
@stop

@section('content')
<div class="row">
    <div class="col-md-8">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ $requisition->title }}</h3>
                <div class="box-tools pull-right">
                    <span class="label label-default">{{ trans('admin/purchase-orders/general.requisition_status_'.$requisition->status) }}</span>
                </div>
            </div>
            <div class="box-body">
                @if ($requisition->printer_comments)
                    {{-- Printed onto the PO the vendor receives. --}}
                    <div class="well well-sm" style="white-space: pre-wrap;">
                        <strong>{{ trans('admin/purchase-orders/general.printer_comments') }}</strong><br>
                        {{ $requisition->printer_comments }}
                    </div>
                @endif

                @if ($requisition->internal_comments)
                    {{-- Never leaves the record. --}}
                    <div class="well well-sm" style="white-space: pre-wrap; background:#fbfbfb;">
                        <strong>{{ trans('admin/purchase-orders/general.internal_comments') }}</strong><br>
                        {{ $requisition->internal_comments }}
                    </div>
                @endif

                @if ($requisition->hasEstimatedLines())
                    <div class="alert alert-warning">
                        {{ trans('admin/purchase-orders/general.requisition_estimate_warning') }}
                    </div>
                @endif

                <div class="table-responsive">
                    <table class="table table-striped table-condensed">
                        <thead>
                            <tr>
                                <th>{{ trans('admin/purchase-orders/general.builder_col_sku') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.builder_col_mfr') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.gl_number') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.builder_col_description') }}</th>
                                <th class="text-right">{{ trans('admin/purchase-orders/general.builder_col_qty') }}</th>
                                <th class="text-right">{{ trans('admin/purchase-orders/general.builder_col_unit_cost') }}</th>
                                <th class="text-right">{{ trans('admin/purchase-orders/general.builder_col_line_total') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {{-- Every value is copyable: this table is what
                                 gets re-typed into Colleague line by line, so
                                 each cell is a paste source rather than
                                 something to read off the screen. --}}
                            @foreach ($requisition->items as $line)
                                @php $gl = $line->gl_number ?: $requisition->default_gl_number; @endphp
                                <tr>
                                    <td><x-copy-field :value="$line->vendor_sku" /></td>
                                    <td><x-copy-field :value="$line->mfr_part_number" /></td>
                                    <td><x-copy-field :value="$gl" /></td>
                                    <td>
                                        <x-copy-field :value="$line->description" />
                                        @if ($line->isEstimate())
                                            <span class="label label-warning">{{ trans('admin/purchase-orders/general.builder_estimate_badge') }}</span>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        <x-copy-field :value="$line->quantity" /> {{ $line->unit_of_measure ?: 'EA' }}
                                    </td>
                                    <td class="text-right">
                                        <x-copy-field :value="number_format((float) $line->unit_cost, 2, '.', '')"
                                                      :display="\App\Helpers\Helper::formatCurrencyOutput($line->unit_cost)" />
                                    </td>
                                    <td class="text-right">
                                        <x-copy-field :value="number_format($line->lineTotal(), 2, '.', '')"
                                                      :display="\App\Helpers\Helper::formatCurrencyOutput($line->lineTotal())" />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="6" class="text-right">{{ trans('admin/purchase-orders/general.builder_subtotal') }}</td>
                                <td class="text-right">{{ \App\Helpers\Helper::formatCurrencyOutput($requisition->subtotal()) }}</td>
                            </tr>
                            <tr>
                                <td colspan="6" class="text-right">{{ trans('admin/purchase-orders/general.builder_shipping') }}</td>
                                <td class="text-right">{{ \App\Helpers\Helper::formatCurrencyOutput($requisition->shipping) }}</td>
                            </tr>
                            <tr>
                                <td colspan="6" class="text-right">{{ trans('admin/purchase-orders/general.builder_gst') }}</td>
                                <td class="text-right">{{ \App\Helpers\Helper::formatCurrencyOutput($requisition->gstAmount()) }}</td>
                            </tr>
                            <tr>
                                <td colspan="6" class="text-right">{{ trans('admin/purchase-orders/general.builder_pst') }}</td>
                                <td class="text-right">{{ \App\Helpers\Helper::formatCurrencyOutput($requisition->pstAmount()) }}</td>
                            </tr>
                            <tr>
                                <td colspan="6" class="text-right"><strong>{{ trans('admin/purchase-orders/general.builder_total') }}</strong></td>
                                <td class="text-right"><strong>{{ \App\Helpers\Helper::formatCurrencyOutput($requisition->total()) }}</strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        {{-- The step that closes the loop with Colleague: the requisition goes
             out as a keying sheet and comes back with a REQM number, then
             later a PO number. Recording either one advances the status. --}}
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/purchase-orders/general.requisition_record_reqm') }}</h3>
            </div>
            <form method="POST" action="{{ route('requisitions.update', $requisition->id) }}">
                {{ csrf_field() }}
                @method('PATCH')
                <div class="box-body">
                    <div class="form-group">
                        <label for="req-number">{{ trans('admin/purchase-orders/general.requisition_number') }}</label>
                        <input type="text" name="requisition_number" id="req-number" class="form-control"
                               value="{{ old('requisition_number', $requisition->requisition_number) }}"
                               placeholder="{{ trans('admin/purchase-orders/general.requisition_number_placeholder') }}">
                        <p class="help-block">{{ trans('admin/purchase-orders/general.requisition_number_help') }}</p>
                    </div>
                    <div class="form-group">
                        <label for="req-status">{{ trans('general.status') }}</label>
                        <select name="status" id="req-status" class="form-control">
                            @foreach (\App\Models\Requisition::STATUSES as $status)
                                <option value="{{ $status }}" {{ $requisition->status === $status ? 'selected' : '' }}
                                        {{ ($status === 'ordered' && ! $requisition->purchase_order_id) ? 'disabled' : '' }}>
                                    {{ trans('admin/purchase-orders/general.requisition_status_'.$status) }}
                                </option>
                            @endforeach
                        </select>
                        @unless ($requisition->purchase_order_id)
                            <p class="help-block">{{ trans('admin/purchase-orders/general.promote_required_for_ordered') }}</p>
                        @endunless
                    </div>
                    <div class="form-group">
                        <label for="req-notes">{{ trans('general.notes') }}</label>
                        <textarea name="notes" id="req-notes" class="form-control" rows="3">{{ old('notes', $requisition->notes) }}</textarea>
                    </div>
                </div>
                <div class="box-footer">
                    <button type="submit" class="btn btn-primary">{{ trans('general.save') }}</button>
                </div>
            </form>
        </div>

        {{-- The crossing into the budget ledger.

             Up to here nothing this requisition says has moved a single
             number in the procurement reports — that is the point of keeping
             baskets out of the purchase_orders table. Promotion is where it
             starts counting, so it is gated on the PDF finance emailed: a
             purchase order that can hold budget without the document that
             issued its number is an entry the reports can't later explain. --}}
        @if ($requisition->purchase_order_id)
            <div class="box box-success">
                <div class="box-header with-border">
                    <h3 class="box-title">{{ trans('admin/purchase-orders/general.po_number') }}</h3>
                </div>
                <div class="box-body">
                    <p>
                        <a href="{{ route('purchase-orders.show', $requisition->purchase_order_id) }}">
                            {{ $requisition->purchaseOrder?->po_number }}
                        </a>
                    </p>
                    <p class="text-muted">{{ trans('admin/purchase-orders/general.promoted_help') }}</p>
                </div>
            </div>

            {{-- The step that used to happen in Outlook.

                 The vendor cannot place a line without a purchase order
                 number, so this panel only exists once one has been issued —
                 and by then the quote has usually come back, which is why the
                 quote fields are here rather than earlier: they are what the
                 invoice will be checked against, and the vendor's figure, not
                 our price list, is the authoritative one. --}}
            @php
                $orderEmails = collect(explode(',', (string) $requisition->supplier?->order_emails))
                    ->map(fn ($email) => trim($email))
                    ->filter()
                    ->values();
                $poDocuments = $requisition->purchaseOrder?->uploads()->pluck('filename') ?? collect();
                $missingParts = $requisition->linesMissingPartNumbers();
                $specialLines = $requisition->specialRequestLines();
                $staleLines = $requisition->linesWithStalePartNumbers();
                $accountFor = fn ($key) => \App\Services\CdwAccounts::label($key);
                $selectedAccount = old('funding_account', $requisition->funding_account);
                $accountSchedules = \App\Services\CdwAccounts::schedulesFor($selectedAccount, $leaseSchedules ?? []);
                $defaultSchedule = \App\Services\CdwAccounts::defaultSchedule($selectedAccount, $leaseSchedules ?? []);
                $needsSchedule = \App\Services\CdwAccounts::needsSchedule($selectedAccount);
                $schedulesByAccount = collect(array_keys(\App\Services\CdwAccounts::ACCOUNTS))
                    ->mapWithKeys(fn ($key) => [$key => [
                        'schedules' => \App\Services\CdwAccounts::schedulesFor($key, $leaseSchedules ?? []),
                        'default' => \App\Services\CdwAccounts::defaultSchedule($key, $leaseSchedules ?? []),
                        'needs' => \App\Services\CdwAccounts::needsSchedule($key),
                    ]]);
            @endphp

            <div class="box {{ $requisition->vendor_sent_at ? 'box-default' : 'box-primary' }}">
                <div class="box-header with-border">
                    <h3 class="box-title">{{ trans('admin/purchase-orders/general.vendor_send_title') }}</h3>
                </div>
                <form method="POST" action="{{ route('requisitions.send-vendor', $requisition->id) }}">
                    {{ csrf_field() }}
                    <div class="box-body">
                        @if ($requisition->vendor_sent_at)
                            <p>
                                <i class="fas fa-paper-plane" aria-hidden="true"></i>
                                {{ trans('admin/purchase-orders/general.vendor_sent_at') }}
                                <strong>{{ \App\Helpers\Helper::getFormattedDateObject($requisition->vendor_sent_at, 'datetime', false) }}</strong>
                            </p>
                            <p class="text-muted">{{ trans('admin/purchase-orders/general.vendor_sent_help') }}</p>
                        @else
                            <p class="text-muted">
                                {{ trans('admin/purchase-orders/general.vendor_send_help', ['supplier' => $requisition->supplier?->name ?: trans('general.supplier')]) }}
                            </p>
                        @endif

                        @if ($orderEmails->isEmpty())
                            <p class="text-danger">{{ trans('admin/purchase-orders/general.vendor_send_help_no_emails') }}</p>
                        @else
                            <p class="text-muted" style="margin-bottom: 4px;">
                                {{ trans('admin/purchase-orders/general.vendor_send_recipients') }}:
                                <span class="text-monospace">{{ $orderEmails->implode(', ') }}</span>
                            </p>
                        @endif

                        @if ($poDocuments->isNotEmpty())
                            <p class="text-muted">
                                {{ trans('admin/purchase-orders/general.vendor_send_attachments') }}:
                                <span class="text-monospace">{{ $poDocuments->implode(', ') }}</span>
                            </p>
                        @endif

                        {{-- Both part numbers are what CDW cannot work
                             without: the MFR# says which product, the EDC is
                             the number they place. Named here rather than only
                             refused on submit, so the fix is visible before the
                             button is pressed. --}}
                        @if ($specialLines->isNotEmpty())
                            {{-- Not a problem: a spec combination we have never
                                 bought has no part numbers anywhere yet, and the
                                 order asks the vendor to price it and issue
                                 them. Stated so nobody reads the send as
                                 complete when it is a question. --}}
                            <p class="text-muted">
                                <i class="fas fa-circle-question" aria-hidden="true"></i>
                                {{ trans_choice('admin/purchase-orders/general.vendor_send_special_lines', $specialLines->count(), ['count' => $specialLines->count()]) }}
                            </p>
                        @endif

                        @if ($staleLines->isNotEmpty())
                            <p class="text-warning">
                                <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
                                {{ trans_choice('admin/purchase-orders/general.vendor_send_stale_parts', $staleLines->count(), ['count' => $staleLines->count()]) }}
                            </p>
                        @endif

                        @if ($missingParts->isNotEmpty())
                            <p class="text-danger">
                                {{ trans_choice('admin/purchase-orders/general.vendor_send_missing_part_numbers', $missingParts->count(), ['count' => $missingParts->count()]) }}
                            </p>
                            <ul class="text-danger">
                                @foreach ($missingParts as $line)
                                    <li>
                                        {{ $line->description }} —
                                        {{ blank($line->mfr_part_number) ? trans('mail.store_vendor_csv_mfr') : '' }}
                                        {{ blank($line->mfr_part_number) && blank($line->vendor_sku) ? '+' : '' }}
                                        {{ blank($line->vendor_sku) ? trans('mail.store_vendor_csv_edc') : '' }}
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        <div class="form-group">
                            <label for="funding-account">{{ trans('admin/purchase-orders/general.vendor_send_account') }} <span class="text-danger">*</span></label>
                            <select name="funding_account" id="funding-account" class="form-control"
                                    data-schedules="{{ json_encode($schedulesByAccount) }}">
                                <option value="">{{ trans('admin/store/general.funding_unset') }}</option>
                                @foreach (array_keys(\App\Services\CdwAccounts::ACCOUNTS) as $account)
                                    <option value="{{ $account }}" {{ $selectedAccount === $account ? 'selected' : '' }}>
                                        {{ $accountFor($account) }}
                                    </option>
                                @endforeach
                            </select>
                            <p class="help-block">{{ trans('admin/purchase-orders/general.vendor_send_account_help') }}</p>
                        </div>

                        {{-- The schedule is not free-form guesswork: two open
                             each quarter, an odd-numbered lease to return and an
                             even-numbered lease to own, and the account decides
                             which of the pair an order rides. So the list is
                             narrowed to the ones this account can use and the
                             current one is preselected. Free text only when the
                             CSI mirror lags behind a schedule CSI has issued —
                             which is exactly when the newest one is needed. --}}
                        <div class="form-group" @if (! $needsSchedule) hidden @endif id="lease-schedule-group">
                            <label for="lease-schedule">{{ trans('admin/purchase-orders/general.vendor_send_lease_schedule') }}</label>
                            @if (empty($accountSchedules))
                                <input type="text" name="lease_schedule" id="lease-schedule" class="form-control"
                                       value="{{ old('lease_schedule', $requisition->lease_schedule) }}" placeholder="301452-000">
                                <p class="help-block">{{ trans('admin/store/general.funding_schedule_none') }}</p>
                            @else
                                <select name="lease_schedule" id="lease-schedule" class="form-control">
                                    @foreach ($accountSchedules as $schedule)
                                        <option value="{{ $schedule }}"
                                            {{ old('lease_schedule', $requisition->lease_schedule ?: $defaultSchedule) === $schedule ? 'selected' : '' }}>
                                            {{ $schedule }}
                                        </option>
                                    @endforeach
                                </select>
                                <p class="help-block">{{ trans('admin/purchase-orders/general.vendor_send_lease_schedule_help') }}</p>
                            @endif
                        </div>

                        {{-- Who else hears about it. Store requesters are
                             already in here without being typed: their lines are
                             on this requisition, and the order email is what
                             tells them it was placed. --}}
                        <div class="form-group">
                            <label for="order-cc">{{ trans('admin/purchase-orders/general.vendor_send_cc') }}</label>
                            <textarea name="order_cc" id="order-cc" rows="2" class="form-control"
                                      placeholder="name@ecuad.ca, other@ecuad.ca">{{ old('order_cc', $requisition->order_cc) }}</textarea>
                            <p class="help-block">{{ trans('admin/purchase-orders/general.vendor_send_cc_help') }}</p>
                            @php $resolvedCc = $requisition->orderCcAddresses(); @endphp
                            @if (! empty($resolvedCc))
                                <p class="help-block">
                                    {{ trans('admin/purchase-orders/general.vendor_send_cc_resolved') }}:
                                    <span class="text-monospace">{{ implode(', ', $resolvedCc) }}</span>
                                </p>
                            @endif
                        </div>

                        <div class="form-group">
                            <label for="quote-number">{{ trans('admin/purchase-orders/general.quote_number') }}</label>
                            <input type="text" name="quote_number" id="quote-number" class="form-control"
                                   value="{{ old('quote_number', $requisition->quote_number) }}">
                            <p class="help-block">{{ trans('admin/purchase-orders/general.quote_number_help') }}</p>
                        </div>

                        <div class="form-group">
                            <label for="quote-total">{{ trans('admin/purchase-orders/general.quote_total') }}</label>
                            <input type="number" step="0.01" min="0" name="quote_total" id="quote-total" class="form-control"
                                   value="{{ old('quote_total', $requisition->quote_total) }}">
                            <p class="help-block">{{ trans('admin/purchase-orders/general.quote_total_help') }}</p>
                        </div>

                        <div class="form-group">
                            <label for="quote-expires">{{ trans('admin/purchase-orders/general.quote_expires_at') }}</label>
                            <input type="date" name="quote_expires_at" id="quote-expires" class="form-control"
                                   value="{{ old('quote_expires_at', $requisition->quote_expires_at?->format('Y-m-d')) }}">
                        </div>
                    </div>
                    <div class="box-footer">
                        <button type="submit" name="test" value="1" class="btn btn-default btn-block">
                            {{ trans('admin/purchase-orders/general.vendor_send_test_submit') }}
                        </button>
                        <button type="submit" class="btn btn-primary btn-block"
                                {{ $orderEmails->isEmpty() || $missingParts->isNotEmpty() ? 'disabled' : '' }}>
                            {{ trans('admin/purchase-orders/general.vendor_send_submit') }}
                        </button>
                    </div>
                </form>

                <script>
                // The schedule field follows the account, because the two are
                // one decision: an admin laptop rides the quarter's odd-numbered
                // lease to return, a curriculum workstation the even-numbered
                // lease to own, and a cash purchase rides neither. Switching the
                // account therefore swaps the offered pair rather than leaving a
                // reference that would reach the vendor against the wrong
                // blanket purchase order.
                (function () {
                    var account = document.getElementById('funding-account');
                    var group = document.getElementById('lease-schedule-group');
                    if (! account || ! group) { return; }

                    var map = JSON.parse(account.dataset.schedules || '{}');

                    account.addEventListener('change', function () {
                        var entry = map[account.value] || { schedules: [], needs: false, default: null };
                        group.hidden = ! entry.needs;

                        var field = document.getElementById('lease-schedule');
                        if (! field || field.tagName !== 'SELECT') { return; }

                        var current = field.value;
                        field.innerHTML = '';
                        entry.schedules.forEach(function (schedule) {
                            var option = document.createElement('option');
                            option.value = schedule;
                            option.textContent = schedule;
                            option.selected = schedule === current || (current === '' && schedule === entry.default);
                            field.appendChild(option);
                        });
                        if (! field.value && entry.default) { field.value = entry.default; }
                    });
                })();
                </script>
            </div>

            {{-- What comes back, which is not one step.

                 CDW's rep set the loop out plainly: we send, they answer with
                 changes, we accept those, they send the final quote, we accept
                 that, they issue an order number. Each of those is a different
                 person's decision on a different day, so each is recorded
                 separately — one "ordered" flag would have this reading as
                 placed while a substitution is still unanswered. --}}
            @if ($requisition->vendor_sent_at)
                <div class="box box-default">
                    <div class="box-header with-border">
                        <h3 class="box-title">{{ trans('admin/purchase-orders/general.vendor_response_title') }}</h3>
                        <div class="box-tools pull-right">
                            <span class="label label-default">
                                {{ trans('admin/purchase-orders/general.vendor_stage_'.$requisition->vendorStage()) }}
                            </span>
                        </div>
                    </div>
                    <div class="box-body">
                        <p class="text-muted">{{ trans('admin/purchase-orders/general.vendor_response_help') }}</p>

                        <table class="table table-condensed">
                            <tbody>
                                <tr>
                                    <td>{{ trans('admin/purchase-orders/general.vendor_sent_at') }}</td>
                                    <td>{{ \App\Helpers\Helper::getFormattedDateObject($requisition->vendor_sent_at, 'datetime', false) }}</td>
                                </tr>
                                @if ($requisition->vendor_changes_at)
                                    <tr>
                                        <td>{{ trans('admin/purchase-orders/general.vendor_changes_at') }}</td>
                                        <td>{{ \App\Helpers\Helper::getFormattedDateObject($requisition->vendor_changes_at, 'datetime', false) }}</td>
                                    </tr>
                                @endif
                                @if ($requisition->quote_confirmed_at)
                                    <tr>
                                        <td>{{ trans('admin/purchase-orders/general.vendor_quote_confirmed_at') }}</td>
                                        <td>{{ \App\Helpers\Helper::getFormattedDateObject($requisition->quote_confirmed_at, 'datetime', false) }}</td>
                                    </tr>
                                @endif
                                @if ($requisition->vendor_order_number)
                                    <tr>
                                        <td>{{ trans('admin/purchase-orders/general.vendor_order_number') }}</td>
                                        <td><x-copy-field :value="$requisition->vendor_order_number" /></td>
                                    </tr>
                                @endif
                            </tbody>
                        </table>

                        @if ($requisition->vendor_changes_notes)
                            <div class="well well-sm" style="white-space: pre-wrap;">{{ $requisition->vendor_changes_notes }}</div>
                        @endif
                    </div>

                    @unless ($requisition->vendor_order_number)
                        <div class="box-body" style="border-top: 1px solid var(--surface-border, #e4e9ee);">
                            <form method="POST" action="{{ route('requisitions.vendor-response', $requisition->id) }}">
                                {{ csrf_field() }}
                                <input type="hidden" name="step" value="changes">
                                <div class="form-group">
                                    <label for="vendor-changes-notes">{{ trans('admin/purchase-orders/general.vendor_changes_notes') }}</label>
                                    <textarea name="vendor_changes_notes" id="vendor-changes-notes" rows="3" class="form-control">{{ $requisition->vendor_changes_notes }}</textarea>
                                    <p class="help-block">{{ trans('admin/purchase-orders/general.vendor_changes_help') }}</p>
                                </div>
                                <button type="submit" class="btn btn-default btn-block">
                                    {{ trans('admin/purchase-orders/general.vendor_changes_submit') }}
                                </button>
                            </form>
                        </div>

                        @unless ($requisition->quote_confirmed_at)
                            <div class="box-body" style="border-top: 1px solid var(--surface-border, #e4e9ee);">
                                <form method="POST" action="{{ route('requisitions.vendor-response', $requisition->id) }}">
                                    {{ csrf_field() }}
                                    <input type="hidden" name="step" value="confirm">
                                    <button type="submit" class="btn btn-warning btn-block">
                                        {{ trans('admin/purchase-orders/general.vendor_quote_confirm_submit') }}
                                    </button>
                                </form>
                            </div>
                        @endunless

                        <div class="box-body" style="border-top: 1px solid var(--surface-border, #e4e9ee);">
                            <form method="POST" action="{{ route('requisitions.vendor-response', $requisition->id) }}">
                                {{ csrf_field() }}
                                <input type="hidden" name="step" value="order_number">
                                <div class="form-group">
                                    <label for="vendor-order-number">{{ trans('admin/purchase-orders/general.vendor_order_number') }}</label>
                                    <input type="text" name="vendor_order_number" id="vendor-order-number" class="form-control"
                                           placeholder="PMCN361">
                                    <p class="help-block">{{ trans('admin/purchase-orders/general.vendor_order_number_help') }}</p>
                                </div>
                                <button type="submit" class="btn btn-success btn-block">
                                    {{ trans('admin/purchase-orders/general.vendor_order_number_submit') }}
                                </button>
                            </form>
                        </div>
                    @endunless
                </div>
            @endif
        @elseif ($requisition->status !== 'cancelled')
            <div class="box box-warning">
                <div class="box-header with-border">
                    <h3 class="box-title">{{ trans('admin/purchase-orders/general.promote_title') }}</h3>
                </div>
                <form method="POST" action="{{ route('requisitions.promote', $requisition->id) }}"
                      enctype="multipart/form-data">
                    {{ csrf_field() }}
                    <div class="box-body">
                        <p class="text-muted">{{ trans('admin/purchase-orders/general.promote_help') }}</p>

                        <div class="form-group">
                            <label for="promote-po-number">{{ trans('admin/purchase-orders/general.po_number') }}</label>
                            <input type="text" name="po_number" id="promote-po-number" class="form-control"
                                   value="{{ old('po_number') }}"
                                   placeholder="{{ trans('admin/purchase-orders/general.promote_po_number_placeholder') }}">
                        </div>

                        {{-- The vendor feed sometimes lands a PO here before we
                             get to it; linking that row is the alternative to
                             minting a duplicate. --}}
                        <div class="form-group">
                            <label for="promote-existing">{{ trans('admin/purchase-orders/general.promote_link_existing') }}</label>
                            <select name="purchase_order_id" id="promote-existing" class="form-control">
                                <option value="">{{ trans('admin/purchase-orders/general.promote_create_new') }}</option>
                                @foreach ($purchaseOrders as $id => $poNumber)
                                    <option value="{{ $id }}" {{ (int) old('purchase_order_id') === (int) $id ? 'selected' : '' }}>{{ $poNumber }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="promote-budget">{{ trans('admin/purchase-orders/general.budget') }}</label>
                            <input type="number" step="0.01" min="0" name="budget" id="promote-budget" class="form-control"
                                   value="{{ old('budget', number_format($requisition->total(), 2, '.', '')) }}">
                            <p class="help-block">{{ trans('admin/purchase-orders/general.promote_budget_help') }}</p>
                        </div>

                        <div class="form-group">
                            <label for="promote-fy">{{ trans('admin/purchase-orders/general.fiscal_year') }}</label>
                            <select name="fiscal_year" id="promote-fy" class="form-control">
                                @foreach ($fiscalYears as $fy)
                                    <option value="{{ $fy }}" {{ old('fiscal_year', $requisition->fiscal_year ?: \App\Helpers\Helper::currentFiscalYear()) === $fy ? 'selected' : '' }}>{{ $fy }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="promote-order-date">{{ trans('admin/purchase-orders/general.order_date') }}</label>
                            <input type="date" name="order_date" id="promote-order-date" class="form-control"
                                   value="{{ old('order_date', now()->format('Y-m-d')) }}">
                        </div>

                        <div class="form-group {{ $errors->has('document') ? ' has-error' : '' }}">
                            <label for="promote-document">
                                {{ trans('admin/purchase-orders/general.promote_document') }}
                                <span class="text-danger">*</span>
                            </label>
                            <input type="file" name="document" id="promote-document" accept="application/pdf" required>
                            <p class="help-block">{{ trans('admin/purchase-orders/general.promote_document_help') }}</p>
                            {!! $errors->first('document', '<span class="help-block">:message</span>') !!}
                        </div>
                    </div>
                    <div class="box-footer">
                        <button type="submit" class="btn btn-warning btn-block">
                            {{ trans('admin/purchase-orders/general.promote_submit') }}
                        </button>
                    </div>
                </form>
            </div>
        @endif

        <div class="box box-default">
            <div class="box-body">
                <table class="table table-condensed">
                    <tbody>
                        <tr>
                            <td>{{ trans('admin/purchase-orders/general.builder_title') }}</td>
                            <td><x-copy-field :value="$requisition->title" /></td>
                        </tr>
                        <tr>
                            <td>{{ trans('general.supplier') }}</td>
                            <td><x-copy-field :value="$requisition->supplier?->name" /></td>
                        </tr>
                        <tr>
                            <td>{{ trans('general.company') }}</td>
                            <td><x-copy-field :value="$requisition->company?->name" /></td>
                        </tr>
                        <tr>
                            <td>{{ trans('admin/purchase-orders/general.fiscal_year') }}</td>
                            <td><x-copy-field :value="$requisition->fiscal_year" /></td>
                        </tr>
                        <tr>
                            <td>{{ trans('admin/purchase-orders/general.cost_center') }}</td>
                            <td><x-copy-field :value="$requisition->cost_center" /></td>
                        </tr>
                        <tr>
                            <td>{{ trans('admin/purchase-orders/general.requisition_needed_by') }}</td>
                            <td><x-copy-field :value="$requisition->needed_by?->format('Y-m-d')" /></td>
                        </tr>
                        <tr>
                            <td>{{ trans('admin/purchase-orders/general.gl_number') }}</td>
                            <td><x-copy-field :value="$requisition->default_gl_number" /></td>
                        </tr>
                        <tr>
                            <td>{{ trans('admin/purchase-orders/general.builder_total') }}</td>
                            <td>
                                <x-copy-field :value="number_format($requisition->total(), 2, '.', '')"
                                              :display="\App\Helpers\Helper::formatCurrencyOutput($requisition->total())" />
                            </td>
                        </tr>
                        <tr>
                            <td>{{ trans('admin/purchase-orders/general.requisition_created_by') }}</td>
                            <td>{{ $requisition->adminuser?->present()->fullName ?: trans('general.na') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@include('partials.copy-fields')
@stop

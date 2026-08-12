{{-- Placing the order, and recording what the vendor says back.

     On the purchase order because the purchase order is what authorises the
     spending and what the vendor bills against. The requisition that produced it
     is transient — a basket keyed into Colleague to get this number — so it
     tracks status and this page does the work. --}}
@php
    use App\Services\CdwAccounts;

    $orderEmails = collect(explode(',', (string) $purchaseOrder->supplier?->order_emails))
        ->map(fn ($email) => trim($email))->filter()->values();
    $poDocuments = $purchaseOrder->uploads()->pluck('filename');
    $lines = $purchaseOrder->vendorOrderLines();
    $missingParts = $purchaseOrder->linesMissingPartNumbers();
    $specialLines = $purchaseOrder->specialRequestLines();
    $staleLines = $purchaseOrder->linesWithStalePartNumbers();

    $selectedAccount = CdwAccounts::canonical(old('funding_account', $purchaseOrder->funding_account));
    $accountRows = collect(CdwAccounts::ACCOUNTS)->map(fn ($account, $key) => [
        'key' => $key,
        'kind' => CdwAccounts::kindLabel($key),
        'scope' => CdwAccounts::scopeLabel($key),
        'number' => $account['number'],
        'payee' => trans('admin/store/general.funding_payee_'.$account['payee']),
        'needs_schedule' => CdwAccounts::needsSchedule($key),
        'schedules' => CdwAccounts::schedulesFor($key, $leaseSchedules ?? []),
        'default_schedule' => CdwAccounts::defaultSchedule($key, $leaseSchedules ?? []),
    ])->values();
    $selectedRow = $accountRows->firstWhere('key', $selectedAccount);
@endphp

<div class="box {{ $purchaseOrder->vendor_sent_at ? 'box-default' : 'box-primary' }}">
    <div class="box-header with-border">
        <h3 class="box-title">{{ trans('admin/purchase-orders/general.vendor_send_title') }}</h3>
        <div class="box-tools pull-right">
            <span class="label label-default">{{ trans('admin/purchase-orders/general.vendor_stage_'.$purchaseOrder->vendorStage()) }}</span>
        </div>
    </div>
    <form method="POST" action="{{ route('purchase-orders.send-vendor', $purchaseOrder) }}">
        {{ csrf_field() }}
        <div class="box-body">
            @if ($purchaseOrder->vendor_sent_at)
                <p>
                    <i class="fas fa-paper-plane" aria-hidden="true"></i>
                    {{ trans('admin/purchase-orders/general.vendor_sent_at') }}
                    <strong>{{ \App\Helpers\Helper::getFormattedDateObject($purchaseOrder->vendor_sent_at, 'datetime', false) }}</strong>
                </p>
                <p class="text-muted">{{ trans('admin/purchase-orders/general.vendor_sent_help') }}</p>
            @else
                <p class="text-muted">
                    {{ trans('admin/purchase-orders/general.vendor_send_help', ['supplier' => $purchaseOrder->supplier?->name ?: trans('general.supplier')]) }}
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

            @if ($specialLines->isNotEmpty())
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
                        <li>{{ $line->description }}</li>
                    @endforeach
                </ul>
            @endif

            {{-- The account picker, laid out as a grid rather than a run-on line:
                 there are exactly four accounts and the thing being chosen is a
                 pair — how it pays, whose budget — so the columns are the
                 distinction. Same shape as the contract picker on the
                 disposition grid, for the same reason: a row of aligned columns
                 is scannable where "a · b · c · d" is not. --}}
            <div class="form-group">
                <label for="funding-account">{{ trans('admin/purchase-orders/general.vendor_send_account') }} <span class="text-danger">*</span></label>

                <select name="funding_account" id="funding-account" class="form-control po-account-native" style="position:absolute; opacity:0; pointer-events:none; height:0; padding:0; border:0;"
                        data-accounts="{{ json_encode($accountRows) }}">
                    @foreach ($accountRows as $row)
                        <option value="{{ $row['key'] }}" {{ $selectedAccount === $row['key'] ? 'selected' : '' }}>
                            {{ $row['kind'] }} · {{ $row['scope'] }} · {{ $row['number'] }}
                        </option>
                    @endforeach
                </select>

                <div class="po-combo" data-po-combo>
                    <button type="button" class="po-combo-button" aria-haspopup="listbox" aria-expanded="false">
                        @if ($selectedRow)
                            <span class="po-combo-grid">
                                <span class="po-c-kind">{{ $selectedRow['kind'] }}</span>
                                <span class="po-c-scope">{{ $selectedRow['scope'] }}</span>
                                <span class="po-c-num">{{ $selectedRow['number'] }}</span>
                                <span class="po-c-payee">{{ $selectedRow['payee'] }}</span>
                            </span>
                        @else
                            <span class="po-combo-empty">{{ trans('admin/store/general.funding_unset') }}</span>
                        @endif
                        <span class="po-combo-caret" aria-hidden="true">▾</span>
                    </button>

                    <div class="po-combo-menu" role="listbox" hidden>
                        <div class="po-combo-head po-combo-grid">
                            <span>{{ trans('admin/store/general.funding_col_kind') }}</span>
                            <span>{{ trans('admin/store/general.funding_col_scope') }}</span>
                            <span>{{ trans('admin/store/general.funding_col_number') }}</span>
                            <span>{{ trans('admin/store/general.funding_col_payee') }}</span>
                        </div>
                        @foreach ($accountRows as $row)
                            <button type="button" class="po-combo-option po-combo-grid {{ $selectedAccount === $row['key'] ? 'is-selected' : '' }}"
                                    role="option" aria-selected="{{ $selectedAccount === $row['key'] ? 'true' : 'false' }}"
                                    data-value="{{ $row['key'] }}">
                                <span class="po-c-kind">{{ $row['kind'] }}</span>
                                <span class="po-c-scope">{{ $row['scope'] }}</span>
                                <span class="po-c-num">{{ $row['number'] }}</span>
                                <span class="po-c-payee">{{ $row['payee'] }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>

                <p class="help-block">{{ trans('admin/purchase-orders/general.vendor_send_account_help') }}</p>
            </div>

            <div class="form-group" id="lease-schedule-group" @if (! $selectedRow || ! $selectedRow['needs_schedule']) hidden @endif>
                <label for="lease-schedule">{{ trans('admin/purchase-orders/general.vendor_send_lease_schedule') }}</label>
                @php $scheduleOptions = $selectedRow['schedules'] ?? []; @endphp
                @if (empty($scheduleOptions))
                    {{-- Free text only when the CSI mirror lags a schedule CSI
                         has already issued — which is exactly when the newest
                         one is the one an order needs. --}}
                    <input type="text" name="lease_schedule" id="lease-schedule" class="form-control"
                           value="{{ old('lease_schedule', $purchaseOrder->lease_schedule) }}" placeholder="301452-000">
                    <p class="help-block">{{ trans('admin/store/general.funding_schedule_none') }}</p>
                @else
                    <select name="lease_schedule" id="lease-schedule" class="form-control">
                        @foreach ($scheduleOptions as $schedule)
                            <option value="{{ $schedule }}"
                                {{ old('lease_schedule', $purchaseOrder->lease_schedule ?: ($selectedRow['default_schedule'] ?? null)) === $schedule ? 'selected' : '' }}>
                                {{ $schedule }}
                            </option>
                        @endforeach
                    </select>
                    <p class="help-block">{{ trans('admin/purchase-orders/general.vendor_send_lease_schedule_help') }}</p>
                @endif
            </div>

            <div class="form-group">
                <label for="order-cc-users">{{ trans('admin/purchase-orders/general.vendor_send_cc') }}</label>
                {{-- People first, typed addresses second. Nearly everyone copied
                     on an order has an account here, and picking them avoids the
                     transposed-letter address that bounces silently. The text box
                     stays for the exception: a vendor contact with no account. --}}
                <select class="js-data-ajax" data-endpoint="users" multiple name="cc_users[]" id="order-cc-users"
                        data-placeholder="{{ trans('general.select_user') }}" style="width: 100%">
                    @foreach ($purchaseOrder->orderCcUsers() as $ccUser)
                        <option value="{{ $ccUser->id }}" selected>{{ $ccUser->present()->fullName }} ({{ $ccUser->email }})</option>
                    @endforeach
                </select>
                <p class="help-block">{{ trans('admin/purchase-orders/general.vendor_send_cc_help') }}</p>

                <label for="order-cc" class="text-muted" style="font-weight:400; margin-top:6px;">
                    {{ trans('admin/purchase-orders/general.vendor_send_cc_external') }}
                </label>
                <textarea name="order_cc" id="order-cc" rows="2" class="form-control"
                          placeholder="rep@cdw.ca">{{ old('order_cc', $purchaseOrder->order_cc) }}</textarea>
                <p class="help-block">{{ trans('admin/purchase-orders/general.vendor_send_cc_external_help') }}</p>
                @php $resolvedCc = $purchaseOrder->orderCcAddresses(); @endphp
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
                       value="{{ old('quote_number', $purchaseOrder->quote_number) }}">
                <p class="help-block">{{ trans('admin/purchase-orders/general.quote_number_help') }}</p>
            </div>

            <div class="form-group">
                <label for="quote-total">{{ trans('admin/purchase-orders/general.quote_total') }}</label>
                <input type="number" step="0.01" min="0" name="quote_total" id="quote-total" class="form-control"
                       value="{{ old('quote_total', $purchaseOrder->quote_total) }}">
                <p class="help-block">{{ trans('admin/purchase-orders/general.quote_total_help') }}</p>
            </div>

            <div class="form-group">
                <label for="quote-expires">{{ trans('admin/purchase-orders/general.quote_expires_at') }}</label>
                <input type="date" name="quote_expires_at" id="quote-expires" class="form-control"
                       value="{{ old('quote_expires_at', $purchaseOrder->quote_expires_at?->format('Y-m-d')) }}">
            </div>
        </div>
        {{-- Once the order is out there is nothing to send — the next move
             belongs to the vendor's panel below. Recording their changes
             reopens the basket, and the buttons with it. --}}
        @if (in_array($purchaseOrder->vendorStage(), ['sent', 'confirmed', 'placed'], true))
            <div class="box-footer">
                <p class="text-muted" style="margin:0;">
                    {{ trans('admin/purchase-orders/general.vendor_send_already_sent', [
                        'date' => \App\Helpers\Helper::getFormattedDateObject($purchaseOrder->vendor_sent_at, 'datetime', false),
                    ]) }}
                </p>
            </div>
        @else
            <div class="box-footer">
                <button type="submit" name="test" value="1" class="btn btn-default btn-block">
                    {{ trans('admin/purchase-orders/general.vendor_send_test_submit') }}
                </button>
                <button type="submit" class="btn btn-primary btn-block"
                        {{ $orderEmails->isEmpty() || $missingParts->isNotEmpty() || $lines->isEmpty() ? 'disabled' : '' }}>
                    {{ trans('admin/purchase-orders/general.vendor_send_submit') }}
                </button>
            </div>
        @endif
    </form>
</div>

@if ($purchaseOrder->vendor_sent_at)
    {{-- What comes back, which is not one step. CDW's rep set the loop out:
         we send, they answer with changes, we accept those, they send the final
         quote, we accept that, they issue an order number. Each is a different
         person's decision on a different day, so each is recorded separately —
         one flag would read as placed while a question was open. --}}
    <div class="box box-default">
        <div class="box-header with-border">
            <h3 class="box-title">{{ trans('admin/purchase-orders/general.vendor_response_title') }}</h3>
        </div>
        <div class="box-body">
            <p class="text-muted">{{ trans('admin/purchase-orders/general.vendor_response_help') }}</p>
            <table class="table table-condensed">
                <tbody>
                    <tr>
                        <td>{{ trans('admin/purchase-orders/general.vendor_sent_at') }}</td>
                        <td>{{ \App\Helpers\Helper::getFormattedDateObject($purchaseOrder->vendor_sent_at, 'datetime', false) }}</td>
                    </tr>
                    @if ($purchaseOrder->vendor_changes_at)
                        <tr>
                            <td>{{ trans('admin/purchase-orders/general.vendor_changes_at') }}</td>
                            <td>{{ \App\Helpers\Helper::getFormattedDateObject($purchaseOrder->vendor_changes_at, 'datetime', false) }}</td>
                        </tr>
                    @endif
                    @if ($purchaseOrder->quote_confirmed_at)
                        <tr>
                            <td>{{ trans('admin/purchase-orders/general.vendor_quote_confirmed_at') }}</td>
                            <td>{{ \App\Helpers\Helper::getFormattedDateObject($purchaseOrder->quote_confirmed_at, 'datetime', false) }}</td>
                        </tr>
                    @endif
                    @if ($purchaseOrder->vendor_order_number)
                        <tr>
                            <td>{{ trans('admin/purchase-orders/general.vendor_order_number') }}</td>
                            <td><x-copy-field :value="$purchaseOrder->vendor_order_number" /></td>
                        </tr>
                    @endif
                </tbody>
            </table>

            @if ($purchaseOrder->vendor_changes_notes)
                <div class="well well-sm" style="white-space: pre-wrap;">{{ $purchaseOrder->vendor_changes_notes }}</div>
            @endif
        </div>

        @unless ($purchaseOrder->vendor_order_number)
            <div class="box-body" style="border-top: 1px solid var(--surface-border, #e4e9ee);">
                <form method="POST" action="{{ route('purchase-orders.vendor-response', $purchaseOrder) }}">
                    {{ csrf_field() }}
                    <input type="hidden" name="step" value="changes">
                    <div class="form-group">
                        <label for="vendor-changes-notes">{{ trans('admin/purchase-orders/general.vendor_changes_notes') }}</label>
                        <textarea name="vendor_changes_notes" id="vendor-changes-notes" rows="3" class="form-control">{{ $purchaseOrder->vendor_changes_notes }}</textarea>
                        <p class="help-block">{{ trans('admin/purchase-orders/general.vendor_changes_help') }}</p>
                    </div>
                    <button type="submit" class="btn btn-default btn-block">
                        {{ trans('admin/purchase-orders/general.vendor_changes_submit') }}
                    </button>
                </form>
            </div>

            @unless ($purchaseOrder->quote_confirmed_at)
                <div class="box-body" style="border-top: 1px solid var(--surface-border, #e4e9ee);">
                    <form method="POST" action="{{ route('purchase-orders.vendor-response', $purchaseOrder) }}">
                        {{ csrf_field() }}
                        <input type="hidden" name="step" value="confirm">
                        <button type="submit" class="btn btn-warning btn-block">
                            {{ trans('admin/purchase-orders/general.vendor_quote_confirm_submit') }}
                        </button>
                    </form>
                </div>
            @endunless

            <div class="box-body" style="border-top: 1px solid var(--surface-border, #e4e9ee);">
                <form method="POST" action="{{ route('purchase-orders.vendor-response', $purchaseOrder) }}">
                    {{ csrf_field() }}
                    <input type="hidden" name="step" value="order_number">
                    <div class="form-group">
                        <label for="vendor-order-number">{{ trans('admin/purchase-orders/general.vendor_order_number') }}</label>
                        <input type="text" name="vendor_order_number" id="vendor-order-number" class="form-control" placeholder="PMCN361">
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

<style>
    /* The four accounts as a grid. Columns, not a run-on line: the choice is a
       pair (how it pays, whose budget) and aligned columns are what make the
       pair readable. No vertical rules — the alignment does that work. */
    .po-combo { position: relative; }
    .po-combo-grid {
        display: grid;
        grid-template-columns: minmax(72px, .8fr) minmax(84px, 1fr) 96px minmax(72px, .8fr);
        gap: 10px;
        align-items: baseline;
        width: 100%;
        text-align: left;
    }
    .po-combo-button {
        width: 100%; display: flex; align-items: center; gap: 8px;
        background: var(--surface, #fff); color: inherit;
        border: 1px solid var(--surface-border, #d2d6de); border-radius: 3px;
        padding: 6px 10px; font-size: 13px; line-height: 1.5;
    }
    .po-combo-button:focus { outline: 2px solid rgba(60, 141, 188, .5); outline-offset: 1px; }
    .po-combo-caret { margin-left: auto; opacity: .55; }
    .po-combo-empty { opacity: .6; }
    .po-combo-menu {
        position: absolute; z-index: 1000; left: 0; right: 0; top: calc(100% + 2px);
        background: var(--surface, #fff);
        border: 1px solid var(--surface-border, #d2d6de); border-radius: 4px;
        box-shadow: 0 6px 18px rgba(0, 0, 0, .12);
        max-height: 280px; overflow-y: auto; padding: 4px 0;
    }
    .po-combo-head {
        padding: 6px 10px 4px; font-size: 10.5px; letter-spacing: .06em;
        text-transform: uppercase; opacity: .6;
        border-bottom: 1px solid var(--surface-border, #ebebee);
    }
    .po-combo-option {
        width: 100%; background: none; border: 0; padding: 7px 10px; font-size: 13px;
        color: inherit;
    }
    .po-combo-option:hover, .po-combo-option:focus, .po-combo-option.is-active { background: rgba(60, 141, 188, .12); }
    .po-combo-option.is-selected { font-weight: 700; }
    .po-c-num { font-family: ui-monospace, Menlo, monospace; opacity: .85; }
    .po-c-payee { opacity: .7; }
</style>

<script>
// The picker is a presentation layer over a real <select>, so the form still
// posts a plain value and validation, `old()` and keyboard use all behave. The
// schedule field follows the account, because the two are one decision: an admin
// laptop rides the quarter's odd-numbered lease to return, a curriculum
// workstation the even-numbered lease to own, and a purchase rides neither.
(function () {
    var native = document.getElementById('funding-account');
    var combo = document.querySelector('[data-po-combo]');
    if (! native || ! combo) { return; }

    var button = combo.querySelector('.po-combo-button');
    var menu = combo.querySelector('.po-combo-menu');
    var options = Array.prototype.slice.call(combo.querySelectorAll('.po-combo-option'));
    var accounts = JSON.parse(native.dataset.accounts || '[]');
    var scheduleGroup = document.getElementById('lease-schedule-group');

    function open() { menu.hidden = false; button.setAttribute('aria-expanded', 'true'); }
    function close() { menu.hidden = true; button.setAttribute('aria-expanded', 'false'); }

    function paintButton(row) {
        button.querySelector('.po-combo-grid, .po-combo-empty').outerHTML = row
            ? '<span class="po-combo-grid">'
                + '<span class="po-c-kind"></span><span class="po-c-scope"></span>'
                + '<span class="po-c-num"></span><span class="po-c-payee"></span></span>'
            : '<span class="po-combo-empty"></span>';

        if (! row) { return; }
        var grid = button.querySelector('.po-combo-grid');
        grid.querySelector('.po-c-kind').textContent = row.kind;
        grid.querySelector('.po-c-scope').textContent = row.scope;
        grid.querySelector('.po-c-num').textContent = row.number;
        grid.querySelector('.po-c-payee').textContent = row.payee;
    }

    function applySchedule(row) {
        if (! scheduleGroup) { return; }
        scheduleGroup.hidden = ! (row && row.needs_schedule);

        var field = document.getElementById('lease-schedule');
        if (! field || field.tagName !== 'SELECT' || ! row) { return; }

        var current = field.value;
        field.innerHTML = '';
        (row.schedules || []).forEach(function (schedule) {
            var option = document.createElement('option');
            option.value = schedule;
            option.textContent = schedule;
            option.selected = schedule === current;
            field.appendChild(option);
        });
        if (! field.value && row.default_schedule) { field.value = row.default_schedule; }
    }

    function choose(value) {
        native.value = value;
        native.dispatchEvent(new Event('change', { bubbles: true }));

        var row = accounts.filter(function (a) { return a.key === value; })[0] || null;
        options.forEach(function (option) {
            var on = option.dataset.value === value;
            option.classList.toggle('is-selected', on);
            option.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        paintButton(row);
        applySchedule(row);
        close();
        button.focus();
    }

    button.addEventListener('click', function () { menu.hidden ? open() : close(); });
    options.forEach(function (option) {
        option.addEventListener('click', function () { choose(option.dataset.value); });
    });
    document.addEventListener('click', function (event) {
        if (! combo.contains(event.target)) { close(); }
    });
    combo.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') { close(); button.focus(); }
    });
})();
</script>

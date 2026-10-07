{{-- "What is the ETA for order X?" from the order page. Opens on exactly what
     would be sent — reps, copies, subject, the lines still to arrive — and
     every part of it can be changed before it goes. Needs $order. --}}
@php
    $eta = app(\App\Services\OrderEtaRequest::class)->preview($order, auth()->user());
@endphp

<button type="button" class="btn btn-sm btn-default" data-eta-open="eta-sheet-{{ $order->id }}"
        @if ($eta['error'] && empty($eta['lines'])) disabled title="{{ $eta['error'] }}" @endif>
    <i class="fas fa-truck-fast" aria-hidden="true"></i> {{ trans('admin/orders/general.eta_request_button') }}
</button>

<dialog id="eta-sheet-{{ $order->id }}" class="eta-sheet" data-eta-sheet aria-label="{{ trans('admin/orders/general.eta_request_title') }}">
    <form method="POST" action="{{ route('orders.eta-request', $order) }}" class="eta-sheet-inner">
        {{ csrf_field() }}
        <header class="eta-head">
            <h3>{{ trans('admin/orders/general.eta_request_title') }}</h3>
            <button type="button" class="close" data-eta-close aria-label="{{ trans('general.cancel') }}">&times;</button>
        </header>

        <div class="eta-body">
            <p class="text-muted">{{ trans('admin/orders/general.eta_request_help') }}</p>

            @if ($order->eta_requested_at)
                <p class="text-warning">
                    {{ trans_choice('admin/orders/general.eta_request_last', $order->eta_request_count, [
                        'date' => \App\Helpers\Helper::getFormattedDateObject($order->eta_requested_at, 'datetime', false),
                        'count' => $order->eta_request_count,
                    ]) }}
                </p>
            @endif

            @if ($eta['error'])
                <p class="text-danger">{{ $eta['error'] }}</p>
            @endif

            <div class="form-group">
                <label for="eta-to-{{ $order->id }}">{{ trans('admin/orders/general.eta_request_to') }}</label>
                <input type="text" name="to_list" id="eta-to-{{ $order->id }}" class="form-control text-monospace"
                       value="{{ implode(', ', $eta['to']) }}">
                <p class="help-block">{{ trans('admin/orders/general.eta_request_to_help') }}</p>
            </div>

            <div class="form-group">
                <label for="eta-cc-{{ $order->id }}">{{ trans('admin/orders/general.eta_request_cc') }}</label>
                <select class="js-data-ajax" data-endpoint="users" multiple name="cc_users[]" id="eta-cc-{{ $order->id }}"
                        data-placeholder="{{ trans('general.select_user') }}" style="width: 100%"></select>
                @if (! empty($eta['cc']))
                    <p class="help-block">
                        {{ trans('admin/orders/general.eta_request_cc_default') }}:
                        <span class="text-monospace">{{ implode(', ', $eta['cc']) }}</span>
                    </p>
                @endif
            </div>

            <div class="form-group">
                <label for="eta-subject-{{ $order->id }}">{{ trans('admin/orders/general.eta_request_subject') }}</label>
                <input type="text" name="subject" id="eta-subject-{{ $order->id }}" class="form-control" maxlength="191"
                       value="{{ $eta['subject'] }}">
            </div>

            <div class="form-group">
                <label for="eta-note-{{ $order->id }}">{{ trans('admin/orders/general.eta_request_note') }}</label>
                <textarea name="note" id="eta-note-{{ $order->id }}" rows="3" class="form-control"></textarea>
                <p class="help-block">{{ trans('admin/orders/general.eta_request_note_help') }}</p>
            </div>

            @if (! empty($eta['lines']))
                <label>{{ trans('admin/orders/general.eta_request_lines') }}</label>
                <table class="eta-lines">
                    <thead><tr>
                        <th>{{ trans('mail.store_vendor_csv_quantity') }}</th>
                        <th>{{ trans('mail.store_vendor_csv_description') }}</th>
                        <th>{{ trans('mail.store_vendor_csv_mfr') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($eta['lines'] as $line)
                        <tr>
                            <td>{{ $line['quantity'] }}</td>
                            <td>{{ $line['description'] }}</td>
                            <td class="text-monospace">{{ $line['mfr_part_number'] ?: '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <footer class="eta-foot">
            <button type="button" class="btn btn-link" data-eta-close>{{ trans('general.cancel') }}</button>
            <button type="submit" name="test" value="1" class="btn btn-default">{{ trans('admin/orders/general.eta_request_test') }}</button>
            <button type="submit" class="btn btn-primary" @disabled(empty($eta['lines']))>{{ trans('admin/orders/general.eta_request_send') }}</button>
        </footer>
    </form>
</dialog>

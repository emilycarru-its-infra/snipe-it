{{-- Which account an order is charged to, and for a lease which CSI
     schedule it rides.

     Rendered into whichever form is asking — the decision form when the
     order is still pending, its own form once it is approved — so the
     account is set at the natural moment rather than on a separate screen.
     The schedule list is read live from the CSI mirror because the open
     schedules roll over quarterly; a hardcoded pair would go stale within
     three months of being written. --}}
<div class="form-group" style="margin-bottom:8px;">
    <label for="{{ $formId }}-account" class="text-muted" style="font-weight:400; font-size:12px;">
        {{ trans('admin/store/general.funding_label') }}
    </label>
    <select name="funding_account" id="{{ $formId }}-account" class="form-control input-sm"
            form="{{ $formId }}" data-lease-toggle="{{ $formId }}-schedule">
        <option value="">{{ trans('admin/store/general.funding_unset') }}</option>
        @foreach ($fundingAccounts as $account)
            <option value="{{ $account }}" @selected($order->funding_account === $account)>
                {{ \App\Services\CdwAccounts::label($account) }}
            </option>
        @endforeach
    </select>
</div>

<div class="form-group" id="{{ $formId }}-schedule" style="margin-bottom:8px;"
     @if ($order->funding_account !== 'lease') hidden @endif>
    <label for="{{ $formId }}-schedule-input" class="text-muted" style="font-weight:400; font-size:12px;">
        {{ trans('admin/store/general.funding_schedule_label') }}
    </label>
    @if (empty($leaseSchedules))
        {{-- Free text rather than nothing: the CSI mirror can lag behind a
             schedule CSI has already issued, and that is exactly when the
             newest one is what an order needs to go against. --}}
        <input type="text" name="lease_schedule" id="{{ $formId }}-schedule-input" form="{{ $formId }}"
               class="form-control input-sm" value="{{ $order->lease_schedule }}" placeholder="301452-000">
        <span class="help-block" style="margin:2px 0 0; font-size:11px;">{{ trans('admin/store/general.funding_schedule_none') }}</span>
    @else
        <select name="lease_schedule" id="{{ $formId }}-schedule-input" form="{{ $formId }}" class="form-control input-sm">
            @foreach ($leaseSchedules as $schedule)
                <option value="{{ $schedule }}" @selected($order->lease_schedule === $schedule)>{{ $schedule }}</option>
            @endforeach
        </select>
    @endif
</div>

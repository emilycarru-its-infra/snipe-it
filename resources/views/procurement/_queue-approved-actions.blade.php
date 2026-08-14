{{-- Everything that happens to an order after it is approved: setting the
     account, sending it to the vendor, and recording what the vendor quoted
     back. Needs $order, $fundingAccounts, $leaseSchedules.

     Shared by the card and the table row so the two cannot drift. --}}

@if ($order->status === 'approved')
    {{-- Approved but not yet sent: the account can still be set or corrected
         here, which is the common case when a schedule rolled over between
         the approval and the batch. --}}
    @include('procurement._queue-funding', ['order' => $order, 'formId' => 'pq-funding-'.$order->id])
    <button type="submit" form="pq-funding-{{ $order->id }}" class="pq-btn pq-btn--quiet" style="font-size:12px; padding:4px 12px;">
        {{ trans('admin/store/general.funding_saved') }}
    </button>

    <div class="pq-actions">
        <button type="submit" form="pq-vendor-{{ $order->id }}" class="pq-btn pq-btn--approve" @disabled(! $order->readyForVendor())>
            {{ trans('admin/store/general.vendor_send_button', ['supplier' => $order->supplier()?->name ?: 'vendor']) }}
        </button>
        <button type="submit" form="pq-vendor-test-{{ $order->id }}" class="pq-btn pq-btn--quiet">
            {{ trans('admin/store/general.vendor_send_test_button') }}
        </button>
    </div>
    @unless ($order->readyForVendor())
        <p class="pq-decided" style="margin-top:6px;">{{ trans('admin/store/general.funding_required') }}</p>
    @endunless
@endif

{{-- Once the request is with CDW the panel becomes about their answer: the
     quote number, its total and its expiry, then our sign-off. The order is
     not placed until that sign-off, so confirm is the last step here. --}}
@if ($order->vendor_sent_at)
    <div style="margin-top:12px; padding-top:10px; border-top:1px solid var(--box-border-color, #f0f0f3);">
        <strong style="font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:var(--text-muted, #777);">
            {{ trans('admin/store/general.quote_heading') }}
        </strong>
        @if ($order->confirmed_at)
            <p class="pq-decided" style="margin-top:4px;">
                {{ $order->quote_number ?: trans('general.na') }} ·
                {{ trans('admin/store/general.quote_confirmed') }} {{ $order->confirmed_at->format('M j, Y') }}
            </p>
        @else
            <div class="form-inline" style="margin-top:6px;">
                <input type="text" name="quote_number" form="pq-quote-{{ $order->id }}"
                       class="form-control input-sm" style="width:110px;"
                       value="{{ $order->quote_number }}" placeholder="{{ trans('admin/store/general.quote_number') }}">
                <input type="number" step="0.01" min="0" name="quote_total" form="pq-quote-{{ $order->id }}"
                       class="form-control input-sm" style="width:100px;"
                       value="{{ $order->quote_total !== null ? (float) $order->quote_total : '' }}"
                       placeholder="{{ trans('admin/store/general.quote_total') }}">
                <input type="date" name="quote_expires_at" form="pq-quote-{{ $order->id }}"
                       class="form-control input-sm" style="width:145px;"
                       value="{{ $order->quote_expires_at?->format('Y-m-d') }}">
            </div>
            <div class="pq-actions">
                <button type="submit" form="pq-quote-{{ $order->id }}" class="pq-btn pq-btn--quiet" style="font-size:12px; padding:4px 12px;">
                    {{ trans('admin/store/general.quote_record') }}
                </button>
                {{-- Same form as Record, so a quote typed and confirmed in one
                     go is stored rather than discarded by the confirm. --}}
                <button type="submit" form="pq-quote-{{ $order->id }}" name="confirm" value="1" class="pq-btn pq-btn--approve" style="font-size:12px; padding:4px 12px;">
                    {{ trans('admin/store/general.quote_confirm') }}
                </button>
            </div>
        @endif
        @if ($order->quote_total !== null && $order->total() > 0)
            @php($variance = round(((float) $order->quote_total - $order->total()) / $order->total() * 100, 1))
            <p class="pq-decided" style="margin-top:6px;">
                ${{ \App\Helpers\Helper::formatCurrencyOutput($order->quote_total) }} —
                {{ trans('admin/store/general.quote_variance', ['percent' => ($variance > 0 ? '+' : '').$variance]) }}
            </p>
        @endif
    </div>
@endif

@if ($order->isFacultyProgram() && in_array($order->status, ['approved', 'ordered'], true))
    <p style="margin-top:8px;">
        <a href="{{ route('user-agreements.create', ['user_id' => $order->user_id]) }}" class="pq-btn pq-btn--quiet" style="font-size:12px; padding:4px 12px; text-decoration:none;">
            {{ trans('admin/store/general.faculty_intake_link') }}
        </a>
    </p>
@endif

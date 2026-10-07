{{-- "Ask for ETAs" on the order list: every order with the vendor and
     something still to arrive, ticked by default except those chased in the
     last week, one email each to its own supplier. Needs $etaCandidates. --}}
<button type="button" class="btn btn-sm btn-default" data-eta-open="eta-sheet-bulk">
    <i class="fas fa-truck-fast" aria-hidden="true"></i>
    {{ trans('admin/orders/general.eta_request_bulk_button') }}
    <span class="badge">{{ $etaCandidates->count() }}</span>
</button>

<dialog id="eta-sheet-bulk" class="eta-sheet" data-eta-sheet aria-label="{{ trans('admin/orders/general.eta_request_bulk_title') }}">
    <form method="POST" action="{{ route('orders.bulk.eta-request') }}" class="eta-sheet-inner">
        {{ csrf_field() }}
        <header class="eta-head">
            <h3>{{ trans('admin/orders/general.eta_request_bulk_title') }}</h3>
            <button type="button" class="close" data-eta-close aria-label="{{ trans('general.cancel') }}">&times;</button>
        </header>

        <div class="eta-body">
            <p class="text-muted">{{ trans('admin/orders/general.eta_request_bulk_help') }}</p>

            @if ($etaCandidates->isEmpty())
                <p class="text-muted">{{ trans('admin/orders/general.eta_request_bulk_none') }}</p>
            @else
                <table class="eta-lines">
                    <tbody>
                    @foreach ($etaCandidates as $candidate)
                        @php $recent = $candidate->eta_requested_at && $candidate->eta_requested_at->gt(now()->subWeek()); @endphp
                        <tr>
                            <td><input type="checkbox" name="ids[]" value="{{ $candidate->id }}" id="eta-bulk-{{ $candidate->id }}" @checked(! $recent)></td>
                            <td><label for="eta-bulk-{{ $candidate->id }}" style="font-weight:600; margin:0;">{{ $candidate->etaReference() }}</label></td>
                            <td>{{ $candidate->supplier->name ?? '—' }}</td>
                            <td>{{ trans('admin/orders/general.status_'.$candidate->status) }}</td>
                            <td class="text-muted">
                                @if ($candidate->eta_requested_at)
                                    {{ trans_choice('admin/orders/general.eta_request_last', $candidate->eta_request_count, [
                                        'date' => $candidate->eta_requested_at->format('Y-m-d'),
                                        'count' => $candidate->eta_request_count,
                                    ]) }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>

                <div class="form-group" style="margin-top:12px;">
                    <label for="eta-bulk-cc">{{ trans('admin/orders/general.eta_request_cc') }}</label>
                    <select class="js-data-ajax" data-endpoint="users" multiple name="cc_users[]" id="eta-bulk-cc"
                            data-placeholder="{{ trans('general.select_user') }}" style="width: 100%"></select>
                </div>

                <div class="form-group">
                    <label for="eta-bulk-note">{{ trans('admin/orders/general.eta_request_note') }}</label>
                    <textarea name="note" id="eta-bulk-note" rows="2" class="form-control"></textarea>
                    <p class="help-block">{{ trans('admin/orders/general.eta_request_note_help') }}</p>
                </div>
            @endif
        </div>

        <footer class="eta-foot">
            <button type="button" class="btn btn-link" data-eta-close>{{ trans('general.cancel') }}</button>
            <button type="submit" class="btn btn-primary" @disabled($etaCandidates->isEmpty())>{{ trans('admin/orders/general.eta_request_bulk_send') }}</button>
        </footer>
    </form>
</dialog>

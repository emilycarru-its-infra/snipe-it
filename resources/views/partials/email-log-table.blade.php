{{-- The email log rows for a record or the report. Expects $emails (EmailLog collection or paginator). --}}
<div class="table-responsive">
    <table class="table table-striped email-log-table">
        <thead>
            <tr>
                <th>{{ trans('admin/email-log/general.sent_at') }}</th>
                <th>{{ trans('admin/email-log/general.recipients') }}</th>
                <th>{{ trans('admin/email-log/general.subject') }}</th>
                <th>{{ trans('admin/email-log/general.kind') }}</th>
                <th>{{ trans('admin/email-log/general.status') }}</th>
            </tr>
        </thead>
        <tbody>
        @forelse ($emails as $email)
            <tr>
                <td style="white-space: nowrap">{{ $email->sent_at ? Helper::getFormattedDateObject($email->sent_at, 'datetime', false) : '' }}</td>
                <td>
                    {{ implode(', ', $email->to_addresses ?? []) }}
                    @if (! empty($email->cc_addresses))
                        <br><small class="text-muted">{{ trans('admin/email-log/general.cc') }}: {{ implode(', ', $email->cc_addresses) }}</small>
                    @endif
                    @if (! empty($email->bcc_addresses))
                        <br><small class="text-muted">{{ trans('admin/email-log/general.bcc') }}: {{ implode(', ', $email->bcc_addresses) }}</small>
                    @endif
                </td>
                <td>{{ $email->subject }}</td>
                <td>
                    {{ $email->kind() }}
                    @if ($email->source !== \App\Models\EmailLog::SOURCE_SNIPE)
                        <span class="label label-default">{{ trans('admin/email-log/general.source_'.$email->source) }}</span>
                    @endif
                </td>
                <td>{{ $email->status }}</td>
            </tr>
        @empty
            <tr><td colspan="5">{{ trans('admin/email-log/general.none') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

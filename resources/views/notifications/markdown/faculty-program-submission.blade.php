@component('mail::message')
# {{ trans($updated ? 'mail.faculty_program_updated_heading' : 'mail.faculty_program_submitted_heading') }}

{{ trans($updated ? 'mail.faculty_program_updated_intro' : 'mail.faculty_program_submitted_intro', [
    'name' => $applicant?->present()->fullName ?? trans('general.unknown'),
]) }}

{{-- The answers, as a plain label/value table rather than prose. Whoever
     works the queue is scanning for two things — how they are paying and
     what happens to the old machine — and a paragraph buries both. --}}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin: 4px 0 12px; border: 1px solid #d8d8dc; border-radius: 10px;">
<tr>
<td style="padding: 16px;">

<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="font-size: 13px;">
<tr>
<td width="40%" style="padding: 4px 0; opacity: .6;">{{ trans('mail.faculty_program_field_applicant') }}</td>
<td style="padding: 4px 0;">{{ $applicant?->present()->fullName ?? trans('general.unknown') }}</td>
</tr>
@if ($applicant?->email)
<tr>
<td width="40%" style="padding: 4px 0; opacity: .6;">{{ trans('mail.faculty_program_field_email') }}</td>
<td style="padding: 4px 0;">{{ $applicant->email }}</td>
</tr>
@endif
<tr>
<td width="40%" style="padding: 4px 0; opacity: .6;">{{ trans('mail.faculty_program_field_payment') }}</td>
<td style="padding: 4px 0;">
{{ $pickup->payment_method
    ? trans('admin/purchase-orders/general.user_agreement_payment_value_'.$pickup->payment_method)
    : trans('mail.faculty_program_not_answered') }}
</td>
</tr>
<tr>
<td width="40%" style="padding: 4px 0; opacity: .6;">{{ trans('mail.faculty_program_field_old_machine') }}</td>
<td style="padding: 4px 0;">
{{-- Buying it out is the answer that costs money, so it is stated as a
     fact here rather than left to be inferred from a missing row. --}}
@if ($buyout)
{{ trans('mail.faculty_program_buyout_yes') }}
@elseif ($asset)
{{ trans('mail.faculty_program_buyout_no') }}
@else
{{ trans('mail.faculty_program_buyout_none') }}
@endif
</td>
</tr>
@if ($asset)
<tr>
<td width="40%" style="padding: 4px 0; opacity: .6;">{{ trans('mail.asset_tag') }}</td>
<td style="padding: 4px 0;">{{ $asset->asset_tag }}@if ($asset->model) — {{ $asset->model->name }}@endif</td>
</tr>
@if ($asset->serial)
<tr>
<td width="40%" style="padding: 4px 0; opacity: .6;">{{ trans('mail.serial') }}</td>
<td style="padding: 4px 0;">{{ $asset->serial }}</td>
</tr>
@endif
@endif
</table>

</td>
</tr>
</table>

@if ($pickup->notes)
**{{ trans('mail.faculty_program_field_notes') }}**

> {{ $pickup->notes }}
@endif

@component('mail::button', ['url' => route('reports.procurement.user-agreement-ledger')])
{{ trans('mail.faculty_program_view_ledger') }}
@endcomponent
@endcomponent

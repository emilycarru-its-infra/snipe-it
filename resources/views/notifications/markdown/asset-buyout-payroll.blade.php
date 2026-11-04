@component('mail::message')
@php($invoice = $invoice ?? false)
# {{ trans($invoice ? 'mail.asset_buyout_invoice_heading' : 'mail.asset_buyout_payroll_heading') }}

{{ trans($invoice ? 'mail.asset_buyout_invoice_intro' : 'mail.asset_buyout_payroll_intro', ['buyer' => $buyer_name ?: '—', 'amount' => $amounts['deduction'] ?? '—']) }}

<x-mail::table>

| | |
| :- | :- |
| {{ trans('mail.asset_buyout_payroll_employee') }} | {{ $buyer_name ?: '—' }} |
| {{ trans('general.email') }} | {{ $buyer->email ?? '—' }} |
| {{ trans('general.employee_number') }} | {{ ($buyer->employee_num ?? null) ?: '—' }} |
| {{ trans($invoice ? 'mail.asset_buyout_invoice_amount' : 'mail.asset_buyout_payroll_deduction') }} | **{{ $amounts['deduction'] ?? '—' }}** |
| {{ trans('mail.asset_buyout_payroll_approved') }} | {{ optional($buyout->approved_at)->toDateString() ?: '—' }} |
@if ($buyout->agreement?->signed_at)
| {{ trans('mail.asset_buyout_payroll_signed') }} | {{ $buyout->agreement->signed_at->toDateString() }} |
@endif

</x-mail::table>

## {{ trans('mail.asset_buyout_payroll_quote_heading') }}

<x-mail::table>

| | |
| :- | :- |
| {{ trans('general.lessor') }} | {{ $lessor->name ?? '—' }} |
| {{ trans('mail.asset_buyout_payroll_quoted') }} | {{ optional($buyout->quoted_at)->toDateString() ?: '—' }} |
| {{ trans('mail.asset_buyout_payroll_quote') }} | {{ $amounts['quote'] ?? '—' }} |
@if ((float) $buyout->remaining_rent > 0)
| {{ trans('mail.asset_buyout_payroll_remaining_rent') }} | {{ $amounts['remaining_rent'] }} |
@endif
| {{ trans('mail.asset_buyout_payroll_total') }} | {{ $amounts['quote_total'] ?? '—' }} |
@if ((float) $buyout->ecu_amount > 0)
| {{ trans('mail.asset_buyout_payroll_ecu') }} | {{ $amounts['ecu'] }} |
@endif

</x-mail::table>

## {{ trans('mail.asset_buyout_payroll_device_heading') }}

<x-mail::table>

| | |
| :- | :- |
| {{ trans('general.asset_tag') }} | {{ $asset->asset_tag ?? '—' }} |
| {{ trans('admin/hardware/form.serial') }} | {{ ($asset->serial ?? null) ?: '—' }} |
| {{ trans('general.asset_model') }} | {{ $asset->model->name ?? '—' }} |
| {{ trans('admin/contracts/general.contract_number') }} | {{ $lease['contract_id'] ?: '—' }} |
| {{ trans('general.lease_end_date') }} | {{ $lease['end_date'] ?: '—' }} |

</x-mail::table>

@if ($buyout->agreement?->signed_pdf_path)
{{ trans('mail.asset_buyout_payroll_attached') }}

@endif
{{ trans($invoice ? 'mail.asset_buyout_invoice_body' : 'mail.asset_buyout_payroll_body') }}
@endcomponent

@component('mail::message')
# {{ trans('mail.early_refresh_request_heading') }}

{{ trans('mail.early_refresh_request_intro', ['name' => $requester->display_name ?? '']) }}

<x-mail::table>

| | |
| :- | :- |
| {{ trans('general.asset_tag') }} | {{ $asset->asset_tag }} |
| {{ trans('admin/hardware/form.serial') }} | {{ $asset->serial ?: '—' }} |
| {{ trans('general.asset_model') }} | {{ $asset->model->name ?? '—' }} |
| {{ trans('general.category') }} | {{ $asset->model->category->name ?? '—' }} |

</x-mail::table>

@if ($note)
{{ trans('mail.early_refresh_request_note_label') }}

> {{ $note }}
@endif

{{ trans('mail.early_refresh_request_body') }}
@endcomponent

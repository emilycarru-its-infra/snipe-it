@if ($agreements->isEmpty())
    <p class="text-muted">{{ trans('admin/forms/general.agreements_tab_empty') }}</p>
@else
    <table class="table table-striped table-hover">
        <thead>
            <tr>
                <th>{{ trans('admin/forms/faculty-program.submission_type') }}</th>
                <th>{{ trans('admin/forms/faculty-program.submission_stage') }}</th>
                <th>{{ trans('admin/forms/faculty-program.submission_asset') }}</th>
                <th>{{ trans('admin/forms/faculty-program.submission_created') }}</th>
                <th>{{ trans('admin/forms/general.agreements_tab_pdf') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($agreements as $agreement)
                <tr>
                    <td>{{ $agreement->agreement_type }}</td>
                    <td>{{ $agreement->lifecycle_stage }}</td>
                    <td>
                        @if ($agreement->asset)
                            <a href="{{ route('hardware.show', $agreement->asset->id) }}">
                                {{ $agreement->asset->asset_tag }}
                            </a>
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ $agreement->created_at?->toDateString() ?? '—' }}</td>
                    <td>
                        @if ($agreement->pdf_path || $agreement->signed_pdf_path)
                            <a href="{{ route('user-agreements.pdf', $agreement->id) }}" target="_blank" rel="noopener">
                                <i class="fas fa-file-pdf" aria-hidden="true"></i>
                                @if ($agreement->signed_pdf_path)
                                    {{ trans('admin/forms/faculty-program.submission_pdf_signed') }}
                                @else
                                    {{ trans('admin/forms/faculty-program.submission_pdf_preview') }}
                                @endif
                            </a>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

<div class="col-md-6">
    <div class="box box-default">
        <div class="box-header with-border">
            <h2 class="box-title">
                <i class="{{ $meta['icon'] ?? 'fas fa-file-alt' }} fa-fw" aria-hidden="true"></i>
                {{ trans($meta['label_key']) }}
            </h2>
            <div class="box-tools pull-right">
                @if ($isAdmin)
                    <span class="label label-primary">{{ trans('admin/forms/general.admin_badge') }}</span>
                @elseif ($canSubmit)
                    <span class="label label-success">{{ trans('admin/forms/general.submit_badge') }}</span>
                @endif
            </div>
        </div>
        <div class="box-body">
            <p class="text-muted">{{ trans($meta['description_key']) }}</p>
            <div style="margin-top:12px;">
                @if ($canSubmit)
                    <a href="{{ route('forms.show', $slug) }}" class="btn btn-primary">
                        <i class="fas fa-pen-to-square" aria-hidden="true"></i>
                        {{ trans('admin/forms/general.open_form') }}
                    </a>
                @endif
                @if ($isAdmin)
                    <a href="{{ route('forms.submissions.index', $slug) }}" class="btn btn-default">
                        <i class="fas fa-list" aria-hidden="true"></i>
                        {{ trans('admin/forms/general.view_submissions') }}
                    </a>
                @endif
            </div>
        </div>
    </div>
</div>

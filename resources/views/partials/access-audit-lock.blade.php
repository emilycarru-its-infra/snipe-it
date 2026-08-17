{{-- The lock on every sensitive page. Superadmin-only, and only on the
     pages the audit knows how to answer for, so it never appears as a
     control that does nothing. Uses the record lightbox — the audit is a
     real page, so it can equally be opened full, bookmarked, or sent to
     whoever is asking the question. --}}
@auth
    @if (auth()->user()->isSuperUser() && \App\Services\PageAccessAudit::isAuditable(request()->path()))
        <a href="{{ route('access-audit.show', ['path' => request()->path()]) }}"
           class="btn btn-sm btn-default js-lightbox"
           data-tooltip="true"
           title="{{ trans('admin/access-audit/general.button') }}">
            <i class="fas fa-lock" aria-hidden="true"></i>
            <span class="sr-only">{{ trans('admin/access-audit/general.button') }}</span>
        </a>
    @endif
@endauth

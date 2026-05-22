{{-- Controls for the GL Journal Transfer report: status filter, and the
     lifecycle hand-off buttons (draft → posted → transferred). --}}
@php
    $base = array_filter(['fiscal_year' => $fiscalYear]);
    $statusTabs = [
        '' => trans('general.all'),
        'draft' => 'Draft',
        'posted' => 'Posted',
        'transferred' => 'Transferred',
    ];
@endphp

<div class="btn-group" role="group" style="margin-right:8px;">
    @foreach ($statusTabs as $value => $label)
        <a href="{{ route('reports.procurement.gl-transfer', $value === '' ? $base : array_merge($base, ['status' => $value])) }}"
           class="btn btn-sm {{ (string) ($status ?? '') === (string) $value ? 'btn-primary' : 'btn-default' }}">
            {{ $label }}
        </a>
    @endforeach
</div>

@if ($draftCount > 0)
    <form method="POST" action="{{ route('reports.procurement.gl-transfer.post') }}" style="display:inline-block; margin-right:8px;">
        @csrf
        @if ($fiscalYear)
            <input type="hidden" name="fiscal_year" value="{{ $fiscalYear }}">
        @endif
        <button type="submit" class="btn btn-sm btn-primary">
            <i class="fa-solid fa-stamp" aria-hidden="true"></i>
            {{ trans('admin/purchase-orders/general.gl_transfer_mark_posted', ['count' => $draftCount]) }}
        </button>
    </form>
@endif

@if ($postedCount > 0)
    <form method="POST" action="{{ route('reports.procurement.gl-transfer.transfer') }}" style="display:inline-block; margin-right:8px;">
        @csrf
        @if ($fiscalYear)
            <input type="hidden" name="fiscal_year" value="{{ $fiscalYear }}">
        @endif
        <button type="submit" class="btn btn-sm btn-success">
            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
            {{ trans('admin/purchase-orders/general.gl_transfer_mark_transferred', ['count' => $postedCount]) }}
        </button>
    </form>
@endif

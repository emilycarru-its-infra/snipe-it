{{-- Mark-posted control for the GL Journal Transfer report. Flips draft
     transactions to "posted" — the hand-off-to-Finance step. Only shown
     when there is draft work to post. --}}
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

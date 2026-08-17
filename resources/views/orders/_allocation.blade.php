{{-- The allocation panel: hardware that arrived without a matching
     request, each unit paired to the waiting store requests for the same
     model — the manual form of the webhook's automatic claim. Needs
     $arrivals and $waiting; the ord-alloc styles come from the host
     page. --}}
    <div class="ord-alloc">
        <h4 style="margin:0 0 4px;">{{ trans('admin/orders/general.allocation_heading') }}
            <span class="badge">{{ $arrivals->count() }}</span></h4>
        <p class="ord-meta" style="margin:0 0 8px;">{{ trans('admin/orders/general.allocation_intro') }}</p>

        @foreach ($arrivals as $arrival)
            @php $candidates = $waiting->where('model_id', $arrival->model_id); @endphp
            <div class="ord-alloc-row">
                <span class="ecu-tag">{{ $arrival->asset_tag }}</span>
                <span>{{ $arrival->model->name ?? '' }}</span>
                <span class="ecu-tag ord-meta">{{ $arrival->serial }}</span>
                @if ($arrival->order_number)
                    <span class="ord-meta">{{ trans('admin/orders/general.allocation_from_order') }} {{ $arrival->order_number }}</span>
                @endif

                @if ($candidates->isEmpty())
                    <span class="ord-meta" style="margin-left:auto;">{{ trans('admin/orders/general.allocation_none_waiting') }}</span>
                @else
                    <form method="POST" action="{{ route('orders.allocate') }}" class="form-inline" style="margin-left:auto;">
                        {{ csrf_field() }}
                        <input type="hidden" name="arrival_id" value="{{ $arrival->id }}">
                        <select name="waiting_id" class="form-control input-sm">
                            {{-- Oldest request first — the default is the FIFO
                                 answer; the dropdown is the human override. --}}
                            @foreach ($candidates as $candidate)
                                <option value="{{ $candidate->id }}">
                                    {{ $candidate->asset_tag }}
                                    · {{ $candidate->name ?: trans('general.na') }}
                                    · {{ $candidate->order_number }}
                                    · {{ trans('admin/orders/general.waiting_since') }} {{ $candidate->created_at->format('M j') }}
                                </option>
                            @endforeach
                        </select>
                        <button type="submit" class="btn btn-sm btn-warning">{{ trans('admin/orders/general.allocate_button') }}</button>
                    </form>
                @endif
            </div>
        @endforeach
    </div>

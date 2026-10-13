{{-- Line table for a board lightbox. Needs $lines (store order, requisition
     or order lines); $withReceived adds the received date column. --}}
<div class="table-responsive">
    <table class="table table-striped">
        <thead>
            <tr>
                <th>{{ trans('admin/orders/general.item') }}</th>
                <th class="text-right">{{ trans('general.qty') }}</th>
                <th class="text-right">{{ trans('admin/orders/general.unit_cost') }}</th>
                @if ($withReceived ?? false)
                    <th>{{ trans('admin/orders/general.received') }}</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                <tr>
                    <td>
                        @if (($line->item_type ?? null) === \App\Models\Asset::class && $line->item)
                            <a class="js-lightbox" href="{{ route('hardware.show', $line->item_id) }}">{{ $line->item->asset_tag }} — {{ $line->item->name ?: ($line->item->model->name ?? '') }}</a>
                        @else
                            {{ $line->description ?: '—' }}
                        @endif
                    </td>
                    <td class="text-right">{{ $line->quantity }}</td>
                    <td class="text-right pp-money">${{ \App\Helpers\Helper::formatCurrencyOutput($line->unit_cost) }}</td>
                    @if ($withReceived ?? false)
                        <td>{{ $line->received_at?->format('Y-m-d') ?? '—' }}</td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

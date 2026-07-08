{{-- Per-device CSI lease panel (asset detail → CSI Lease tab).
     $csi = App\Services\CsiReconciliation::forAsset() result. --}}
@php
    $stateClass = ['accepted' => 'label-success', 'in_process' => 'label-info', 'snipe_only' => 'label-warning'][$csi['state']] ?? 'label-default';
    $reconClass = ['match' => 'label-success'][$csi['recon']] ?? 'label-warning';
    $sched = $csi['schedule'] ?? null;
@endphp

<div class="row" style="margin-top:10px;">
    <div class="col-md-12">
        <p class="text-muted">{{ trans('admin/hardware/csi.mirror_note') }}</p>
    </div>

    <div class="col-md-6">
        <table class="table table-striped">
            <tbody>
                <tr>
                    <td><strong>{{ trans('admin/hardware/csi.lifecycle') }}</strong></td>
                    <td><span class="label {{ $stateClass }}">{{ trans('admin/hardware/csi.state_'.$csi['state']) }}</span></td>
                </tr>
                <tr>
                    <td><strong>{{ trans('admin/hardware/csi.recon') }}</strong></td>
                    <td><span class="label {{ $reconClass }}">{{ trans('admin/hardware/csi.recon_'.$csi['recon']) }}</span></td>
                </tr>
                <tr>
                    <td><strong>{{ trans('admin/hardware/csi.csi_schedule') }}</strong></td>
                    <td>{{ $csi['csi_schedule_ref'] ?: '—' }}</td>
                </tr>
                <tr>
                    <td><strong>{{ trans('admin/hardware/csi.snipe_schedule') }}</strong></td>
                    <td>{{ $csi['snipe_schedule_ref'] ?: '—' }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    @if ($sched)
        <div class="col-md-6">
            <h4>{{ trans('admin/hardware/csi.schedule_terms') }} — {{ $sched->schedule_name }}</h4>
            <table class="table table-striped">
                <tbody>
                    <tr><td><strong>{{ trans('admin/hardware/csi.term_start') }}</strong></td><td>{{ $sched->term_start_date?->format('Y-m-d') ?: '—' }}</td></tr>
                    <tr><td><strong>{{ trans('admin/hardware/csi.term_end') }}</strong></td><td>{{ $sched->term_end_date?->format('Y-m-d') ?: '—' }}</td></tr>
                    <tr><td><strong>{{ trans('admin/hardware/csi.rent') }}</strong></td><td>{{ $sched->rent ? \App\Helpers\Helper::formatCurrencyOutput($sched->rent) : '—' }}</td></tr>
                    <tr><td><strong>{{ trans('admin/hardware/csi.payment_frequency') }}</strong></td><td>{{ $sched->payment_frequency ?: '—' }}</td></tr>
                </tbody>
            </table>
        </div>
    @endif
</div>

<div class="row">
    <div class="col-md-12">
        <h4>{{ trans('admin/hardware/csi.rent_invoices') }}</h4>
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>{{ trans('admin/hardware/csi.invoice_number') }}</th>
                    <th>{{ trans('admin/hardware/csi.invoice_date') }}</th>
                    <th class="text-right">{{ trans('admin/hardware/csi.invoice_amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($csi['invoices'] as $inv)
                    <tr>
                        <td>{{ $inv->csi_invoice_number }}</td>
                        <td>{{ $inv->invoice_date?->format('Y-m-d') ?: '—' }}</td>
                        <td class="text-right">{{ \App\Helpers\Helper::formatCurrencyOutput($inv->amount) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3">{{ trans('admin/hardware/csi.no_invoices') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- "+ New Wave" as an anchored popover instead of a separate page: the
     button opens a card right where the click happened, the wave form posts
     to the normal store route. Expects $popoverId, $fy, $types. --}}
<span class="nw-pop-wrap" style="position:relative; display:inline-block;">
    <button type="button" class="btn btn-primary nw-pop-toggle" data-pop="{{ $popoverId }}">
        <i class="fas fa-plus"></i> {{ trans('admin/deployments/general.add_wave') }}
    </button>
    <div class="nw-pop" id="{{ $popoverId }}">
        <form method="POST" action="{{ route('deployment-waves.store') }}">
            @csrf
            <input type="hidden" name="fiscal_year" value="{{ $fy }}">
            <input type="hidden" name="wave_state" value="planned">
            <div class="form-group" style="margin-bottom:8px;">
                <label style="margin-bottom:2px;">{{ trans('admin/deployments/general.name') }}</label>
                <input type="text" name="name" class="form-control input-sm" required placeholder="{{ $fy }} Refresh">
            </div>
            <div class="form-group" style="margin-bottom:8px;">
                <label style="margin-bottom:2px;">{{ trans('admin/deployments/general.deployment_type') }}</label>
                <select name="deployment_type_id" class="form-control input-sm">
                    @foreach ($types as $type)
                        <option value="{{ $type->id }}" {{ $type->slug === 'refresh' ? 'selected' : '' }}>{{ $type->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group" style="margin-bottom:8px;">
                <label style="margin-bottom:2px;">{{ trans('admin/deployments/general.arrival_window') }}</label>
                <div style="display:flex; gap:6px;">
                    <input type="date" name="arrival_window_start" class="form-control input-sm">
                    <input type="date" name="arrival_window_end" class="form-control input-sm">
                </div>
            </div>
            <div class="form-group" style="margin-bottom:10px;">
                <label style="margin-bottom:2px;">{{ trans('admin/deployments/general.deploy_window') }}</label>
                <div style="display:flex; gap:6px;">
                    <input type="date" name="target_start_date" class="form-control input-sm">
                    <input type="date" name="target_end_date" class="form-control input-sm">
                </div>
            </div>
            <div class="text-right">
                <button type="button" class="btn btn-sm btn-default nw-pop-cancel">{{ trans('button.cancel') }}</button>
                <button type="submit" class="btn btn-sm btn-primary">{{ trans('admin/deployments/general.add_wave') }}</button>
            </div>
        </form>
    </div>
</span>
@once
<style>
    .nw-pop {
        display: none;
        position: absolute;
        top: calc(100% + 8px);
        left: 0;
        z-index: 1040;
        width: 320px;
        padding: 14px;
        background: var(--box-bg, #fff);
        border: 1px solid var(--box-border-color, #e5e5e5);
        border-radius: 12px;
        box-shadow: 0 12px 32px rgba(0, 0, 0, .18);
        text-align: left;
    }
    .nw-pop.open { display: block; }
    .nw-pop::before {
        content: '';
        position: absolute;
        top: -7px;
        left: 24px;
        width: 12px;
        height: 12px;
        background: var(--box-bg, #fff);
        border-left: 1px solid var(--box-border-color, #e5e5e5);
        border-top: 1px solid var(--box-border-color, #e5e5e5);
        transform: rotate(45deg);
    }
</style>
<script nonce="{{ csrf_token() }}">
document.addEventListener('click', function (e) {
    var toggle = e.target.closest('.nw-pop-toggle');
    if (toggle) {
        var pop = document.getElementById(toggle.getAttribute('data-pop'));
        pop.classList.toggle('open');
        return;
    }
    if (e.target.closest('.nw-pop-cancel')) {
        e.target.closest('.nw-pop').classList.remove('open');
        return;
    }
    if (!e.target.closest('.nw-pop')) {
        document.querySelectorAll('.nw-pop.open').forEach(function (pop) { pop.classList.remove('open'); });
    }
});
</script>
@endonce

{{-- Storage configuration as an anchored panel on the page it governs:
     which rooms hold staged devices and how many each takes, plus the room
     each wave stages in. Both were previously reachable only by editing a
     location and each wave separately, which is why neither had ever been
     set. Expects $configRooms, $configWaves, $allLocations. --}}
<span class="nw-pop-wrap" style="position:relative; display:inline-block;">
    <button type="button" class="btn btn-sm btn-default nw-pop-toggle" data-pop="storage-config">
        <i class="fas fa-sliders-h"></i> {{ trans('admin/deployments/general.storage_config') }}
    </button>
    <div class="nw-pop nw-pop-wide nw-pop-right" id="storage-config">
        <form method="POST" action="{{ route('deployments.storage.config') }}">
            @csrf

            <h4 style="margin:0 0 4px;">{{ trans('admin/deployments/general.storage_config_rooms') }}</h4>
            <p class="text-muted" style="margin-bottom:8px;">{{ trans('admin/deployments/general.storage_config_rooms_help') }}</p>

            <table class="table table-condensed" style="margin-bottom:6px;" id="storage-config-rooms">
                <thead>
                    <tr>
                        <th>{{ trans('admin/deployments/general.storage_config_room') }}</th>
                        <th style="width:110px;">{{ trans('admin/deployments/general.storage_capacity') }}</th>
                        <th style="width:34px;"></th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($configRooms as $i => $room)
                    <tr class="storage-config-row">
                        <td>
                            <select name="rooms[{{ $i }}][location_id]" class="form-control input-sm">
                                <option value="">—</option>
                                @foreach ($allLocations as $loc)
                                    <option value="{{ $loc->id }}" {{ (int) $loc->id === (int) $room->id ? 'selected' : '' }}>{{ $loc->name }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td><input type="number" min="0" name="rooms[{{ $i }}][capacity]" class="form-control input-sm" value="{{ $room->storage_capacity }}"></td>
                        <td class="text-right">
                            <button type="button" class="btn btn-xs btn-default storage-config-remove" aria-label="{{ trans('button.delete') }}"><i class="fas fa-times"></i></button>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            <template id="storage-config-room-template">
                <tr class="storage-config-row">
                    <td>
                        <select name="rooms[__i__][location_id]" class="form-control input-sm">
                            <option value="">—</option>
                            @foreach ($allLocations as $loc)
                                <option value="{{ $loc->id }}">{{ $loc->name }}</option>
                            @endforeach
                        </select>
                    </td>
                    <td><input type="number" min="0" name="rooms[__i__][capacity]" class="form-control input-sm"></td>
                    <td class="text-right">
                        <button type="button" class="btn btn-xs btn-default storage-config-remove" aria-label="{{ trans('button.delete') }}"><i class="fas fa-times"></i></button>
                    </td>
                </tr>
            </template>

            <button type="button" class="btn btn-xs btn-default" id="storage-config-add" style="margin-bottom:14px;">
                <i class="fas fa-plus"></i> {{ trans('admin/deployments/general.storage_config_add_room') }}
            </button>

            <h4 style="margin:0 0 4px;">{{ trans('admin/deployments/general.storage_config_waves') }}</h4>
            <p class="text-muted" style="margin-bottom:8px;">{{ trans('admin/deployments/general.storage_config_waves_help') }}</p>

            <table class="table table-condensed" style="margin-bottom:14px;">
                <tbody>
                @forelse ($configWaves as $wave)
                    <tr>
                        <td style="vertical-align:middle;">
                            <span class="label" style="background-color: {{ $wave->displayColor() }}; color:#fff;">{{ $wave->name }}</span>
                        </td>
                        <td style="width:280px;">
                            <select name="waves[{{ $wave->id }}]" class="form-control input-sm">
                                <option value="">—</option>
                                @foreach ($allLocations as $loc)
                                    <option value="{{ $loc->id }}" {{ (int) $loc->id === (int) $wave->storage_location_id ? 'selected' : '' }}>{{ $loc->name }}</option>
                                @endforeach
                            </select>
                        </td>
                    </tr>
                @empty
                    <tr><td class="text-center text-muted">{{ trans('admin/deployments/general.storage_config_no_waves') }}</td></tr>
                @endforelse
                </tbody>
            </table>

            <div class="text-right">
                <button type="button" class="btn btn-sm btn-default nw-pop-cancel">{{ trans('button.cancel') }}</button>
                <button type="submit" class="btn btn-sm btn-primary">{{ trans('general.save') }}</button>
            </div>
        </form>
    </div>
</span>
@include('reports.deployments._popover-assets')

<script nonce="{{ csrf_token() }}">
(function () {
    var table = document.getElementById('storage-config-rooms');
    var add = document.getElementById('storage-config-add');
    var template = document.getElementById('storage-config-room-template');
    if (!table || !add || !template) {
        return;
    }

    // Row indexes only have to be unique within the post — the server reads
    // the submitted set, not the positions — so a counter that never rewinds
    // is enough, and removing a row cannot collide with a later add.
    var next = table.querySelectorAll('.storage-config-row').length;

    add.addEventListener('click', function () {
        var html = template.innerHTML.replace(/__i__/g, next++);
        var body = table.querySelector('tbody');
        body.insertAdjacentHTML('beforeend', html);
        body.lastElementChild.querySelector('select').focus();
    });

    table.addEventListener('click', function (e) {
        var remove = e.target.closest('.storage-config-remove');
        if (remove) {
            remove.closest('.storage-config-row').remove();
        }
    });
})();
</script>

{{--
    Where a checked-in device is stored. A checked-out device is wherever its
    holder is; a checked-in one has no holder, so its storage room is the only
    location it has. The rooms are the ones configured on the storage page, so
    a device checked in here lands on that page's shelf for the room.
--}}
@php
    $storageRooms = \App\Models\Location::storageRooms()->orderBy('name')->get(['id', 'name']);
    $currentRoom = old('storage_location_id', $storageRooms->contains('id', $asset->rtd_location_id) ? $asset->rtd_location_id : null);
@endphp

@if ($storageRooms->isNotEmpty())
    <div class="form-group{{ $errors->has('storage_location_id') ? ' has-error' : '' }}">
        <label for="storage_location_id" class="col-md-3 control-label">
            {{ trans('admin/hardware/form.storage_room') }}
        </label>
        <div class="col-md-7 required">
            <select class="form-control select2" name="storage_location_id" id="storage_location_id" required aria-label="storage_location_id" style="width: 100%">
                <option value="">{{ trans('admin/hardware/form.storage_room_select') }}</option>
                @foreach ($storageRooms as $room)
                    <option value="{{ $room->id }}" @selected((int) $currentRoom === $room->id)>{{ $room->name }}</option>
                @endforeach
            </select>
        </div>
        {!! $errors->first('storage_location_id', '<div class="col-md-8 col-md-offset-3"><span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span></div>') !!}
        <div class="col-md-7 col-md-offset-3">
            <p class="help-block">
                {{ trans('admin/hardware/form.storage_room_help') }}
                @can('deployments.view')
                    <a href="{{ route('deployments.storage') }}">{{ trans('admin/hardware/form.storage_room_manage') }}</a>
                @endcan
            </p>
        </div>
    </div>
@else
    {{-- No storage rooms configured yet: any location will do, and it is still the room the device is stored in. --}}
    @include('partials.forms.edit.location-select', [
        'translated_name' => trans('admin/hardware/form.storage_room'),
        'fieldname' => 'storage_location_id',
        'help_text' => trans('admin/hardware/form.storage_room_help'),
        'item' => null,
        'selected' => (! old('storage_location_id') && $asset->rtd_location_id) ? [$asset->rtd_location_id] : null,
    ])
@endif

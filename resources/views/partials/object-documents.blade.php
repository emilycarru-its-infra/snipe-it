{{--
  Documents panel for any model that registers in
  Controller::$map_object_type. Lists the action_logs filename uploads
  attached to the object with download / delete actions, plus the
  global upload modal trigger.

  Required vars:
    $object         Eloquent model instance with the Loggable trait
    $object_type    String key matching Controller::$map_object_type
                    (e.g. 'purchase-orders', 'lease-schedules')
--}}
<div class="box box-default" id="documents">
    <div class="box-header with-border">
        <h3 class="box-title">{{ trans('general.files') }}</h3>
        <div class="box-tools pull-right">
            @can('files', $object)
                <button type="button" class="btn btn-theme btn-sm" data-toggle="modal" data-target="#uploadFileModal">
                    <i class="fas fa-upload"></i> {{ trans('button.upload') }}
                </button>
            @endcan
        </div>
    </div>
    <div class="box-body">
        @php
            $files = \App\Models\Actionlog::where('item_type', get_class($object))
                ->where('item_id', $object->id)
                ->where('action_type', 'uploaded')
                ->whereNotNull('filename')
                ->orderByDesc('created_at')
                ->get();
        @endphp

        @if ($files->isEmpty())
            <p class="text-muted">{{ trans('general.no_results') }}</p>
        @else
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>{{ trans('general.file_name') }}</th>
                        <th>{{ trans('general.notes') }}</th>
                        <th>{{ trans('general.created_at') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($files as $file)
                    <tr>
                        <td><code>{{ $file->filename }}</code></td>
                        <td>{{ $file->note }}</td>
                        <td>{{ optional($file->created_at)->format('Y-m-d H:i') }}</td>
                        <td class="text-right" style="white-space:nowrap;">
                            <a href="{{ route('ui.files.show', ['object_type' => $object_type, 'id' => $object->id, 'file_id' => $file->id]) }}"
                               class="btn btn-default btn-sm" title="{{ trans('button.download') }}">
                                <i class="fas fa-download"></i>
                            </a>
                            @can('files', $object)
                                <form method="POST"
                                      action="{{ route('ui.files.destroy', ['object_type' => $object_type, 'id' => $object->id, 'file_id' => $file->id]) }}"
                                      style="display:inline-block;"
                                      onsubmit="return confirm('{{ trans('general.are_you_sure') }}');">
                                    {{ csrf_field() }}
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-danger btn-sm" title="{{ trans('general.delete') }}">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            @endcan
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>

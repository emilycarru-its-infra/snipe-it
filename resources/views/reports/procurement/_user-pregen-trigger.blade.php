<div class="well well-sm" style="margin-bottom:15px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
    <div>
        <strong>{{ trans('admin/user-agreements/general.pregen_panel_title') }}</strong>
        <span class="text-muted" style="margin-left:6px;">
            {{ trans('admin/user-agreements/general.pregen_panel_help') }}
        </span>
    </div>
    <form method="POST" action="{{ route('user-agreements.pregen-pdfs') }}" style="display:inline-block;">
        @csrf
        <label class="checkbox-inline" style="margin-right:8px;">
            <input type="checkbox" name="include_sent" value="1"> {{ trans('admin/user-agreements/general.pregen_include_sent') }}
        </label>
        <label class="checkbox-inline" style="margin-right:8px;">
            <input type="checkbox" name="force" value="1"> {{ trans('admin/user-agreements/general.pregen_force') }}
        </label>
        <button type="submit" class="btn btn-primary btn-sm"
                onclick="return confirm('{{ trans('admin/user-agreements/general.pregen_confirm') }}');">
            <i class="fas fa-file-pdf" aria-hidden="true"></i>
            {{ trans('admin/user-agreements/general.pregen_now') }}
        </button>
    </form>
</div>

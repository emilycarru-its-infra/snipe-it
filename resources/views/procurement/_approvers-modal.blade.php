{{-- Who may approve store orders — a setting behind a header button.
     Superuser only. Expects $approvers (StoreApprover with user). --}}
@if (auth()->user()->isSuperUser())
    {{-- A setting, not content: who may approve store orders is configured
         once and then forgotten, so it lives behind a button rather than
         taking a box on the page it governs. --}}
    <div class="modal fade" id="approversModal" tabindex="-1" role="dialog" aria-labelledby="approversModalLabel">
        <div class="modal-dialog" role="document">
            <form method="POST" action="{{ route('procurement.approvers.save') }}">
                {{ csrf_field() }}
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal" aria-label="{{ trans('button.cancel') }}">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        <h4 class="modal-title" id="approversModalLabel">{{ trans('admin/store/general.approvers_title') }}</h4>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted">{{ trans('admin/store/general.approvers_intro') }}</p>
                        <select class="js-data-ajax" data-endpoint="users" multiple name="approvers[]"
                                data-placeholder="{{ trans('general.select_user') }}" style="width:100%;">
                            @foreach ($approvers as $approver)
                                @if ($approver->user)
                                    <option value="{{ $approver->user_id }}" selected>{{ $approver->user->present()->fullName }}</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-default" data-dismiss="modal">{{ trans('button.cancel') }}</button>
                        <button type="submit" class="btn btn-primary">{{ trans('general.save') }}</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
@endif

<div class="modal fade" id="budgetAllocationsModal" tabindex="-1" role="dialog" aria-labelledby="budgetAllocationsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" id="budgetAllocationsModalLabel">
                    {{ trans('admin/budget-allocations/general.manage_title') }}
                </h4>
            </div>
            <div class="modal-body">
                <p class="help-block" style="margin-bottom:15px;">
                    {{ trans('admin/budget-allocations/general.help') }}
                </p>

                {{-- Existing allocations --}}
                @if ($allocations->isNotEmpty())
                    <table class="table table-striped table-condensed">
                        <thead>
                            <tr>
                                <th>{{ trans('admin/contracts/general.fiscal_year') }}</th>
                                <th>{{ trans('admin/budget-allocations/general.area') }}</th>
                                <th>{{ trans('admin/budget-allocations/general.source') }}</th>
                                <th class="text-right">{{ trans('admin/budget-allocations/general.amount') }}</th>
                                <th>{{ trans('admin/budget-allocations/general.added_by') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($allocations as $row)
                                <tr>
                                    <td>{{ $row->fiscal_year }}</td>
                                    <td>{{ $row->area ?: '—' }}</td>
                                    <td>
                                        <span class="label label-{{ $row->source === 'forecast' ? 'info' : ($row->source === 'adjustment' ? 'warning' : 'primary') }}">
                                            {{ trans('admin/budget-allocations/general.source_'.$row->source) }}
                                        </span>
                                        @if ($row->description)
                                            <div class="text-muted small">{{ $row->description }}</div>
                                        @endif
                                    </td>
                                    <td class="text-right">${{ \App\Helpers\Helper::formatCurrencyOutput($row->amount) }}</td>
                                    <td>
                                        {{ $row->creator->present()->fullName ?? '—' }}
                                        <div class="text-muted small">{{ optional($row->effective_date ?? $row->created_at)->toDateString() }}</div>
                                    </td>
                                    <td class="text-right">
                                        <form method="POST" action="{{ route('budget_allocations.destroy', $row->id) }}" style="display:inline;">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-xs btn-danger" title="{{ trans('general.delete') }}">
                                                <i class="fas fa-times" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                            <tr>
                                <th colspan="3" class="text-right">{{ trans('admin/budget-allocations/general.total') }}</th>
                                <th class="text-right">${{ \App\Helpers\Helper::formatCurrencyOutput($allocations->sum('amount')) }}</th>
                                <th colspan="2"></th>
                            </tr>
                        </tbody>
                    </table>
                @else
                    <p class="text-muted">{{ trans('admin/budget-allocations/general.none_yet') }}</p>
                @endif

                <hr/>

                {{-- Add-to-Budget form --}}
                <h4 style="margin-top:0;">{{ trans('admin/budget-allocations/general.add_title') }}</h4>
                <form method="POST" action="{{ route('budget_allocations.store') }}" class="form-horizontal">
                    @csrf
                    <div class="form-group">
                        <label for="ba_fiscal_year" class="col-md-3 control-label">{{ trans('admin/contracts/general.fiscal_year') }}</label>
                        <div class="col-md-7">
                            <input type="text" name="fiscal_year" id="ba_fiscal_year" class="form-control"
                                value="{{ old('fiscal_year', $selectedFy) }}"
                                placeholder="FY2026-27" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="ba_area" class="col-md-3 control-label">{{ trans('admin/budget-allocations/general.area') }}</label>
                        <div class="col-md-7">
                            <input type="text" name="area" id="ba_area" class="form-control"
                                value="{{ old('area') }}" placeholder="Admin / Curriculum / Research / …">
                            <span class="help-block">{{ trans('admin/budget-allocations/general.area_help') }}</span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="ba_amount" class="col-md-3 control-label">{{ trans('admin/budget-allocations/general.amount') }}</label>
                        <div class="col-md-7">
                            <input type="number" step="0.01" name="amount" id="ba_amount" class="form-control"
                                value="{{ old('amount') }}" required>
                            <span class="help-block">{{ trans('admin/budget-allocations/general.amount_help') }}</span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="ba_source" class="col-md-3 control-label">{{ trans('admin/budget-allocations/general.source') }}</label>
                        <div class="col-md-7">
                            <select name="source" id="ba_source" class="form-control" required>
                                @foreach ($budgetSourceLabels as $src)
                                    <option value="{{ $src }}" @selected(old('source') === $src)>
                                        {{ trans('admin/budget-allocations/general.source_'.$src) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="ba_effective_date" class="col-md-3 control-label">{{ trans('admin/budget-allocations/general.effective_date') }}</label>
                        <div class="col-md-7">
                            <input type="date" name="effective_date" id="ba_effective_date" class="form-control"
                                value="{{ old('effective_date') }}">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="ba_description" class="col-md-3 control-label">{{ trans('admin/budget-allocations/general.description') }}</label>
                        <div class="col-md-7">
                            <textarea name="description" id="ba_description" rows="2" class="form-control" placeholder="{{ trans('admin/budget-allocations/general.description_placeholder') }}">{{ old('description') }}</textarea>
                        </div>
                    </div>
                    <div class="form-group">
                        <div class="col-md-7 col-md-offset-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-plus" aria-hidden="true"></i>
                                {{ trans('admin/budget-allocations/general.add_action') }}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">{{ trans('button.close') }}</button>
            </div>
        </div>
    </div>
</div>

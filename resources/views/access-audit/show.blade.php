@extends('layouts/default')

@section('title')
    {{ trans('admin/access-audit/general.title') }} @parent
@stop

@section('header_right')
    @if (auth()->user()->isSuperUser())
        <a href="{{ route('groups.audit') }}" target="_blank" rel="noopener" class="btn btn-sm btn-default">
            <i class="fas fa-table" aria-hidden="true"></i> {{ trans('admin/access-audit/general.full_matrix') }}
        </a>
    @endif
@stop

@section('content')
{{-- The roster is open to everyone the audited page admits, so the links
     out of it — user records, group admin — only render for viewers who
     can actually open those destinations. --}}
@php
    $canOpenUsers = auth()->user()->can('view', \App\Models\User::class);
    $canOpenGroups = auth()->user()->isSuperUser();
@endphp
<x-container>

    <x-box>
        <p class="text-muted" style="margin-bottom: 15px;">{{ trans('admin/access-audit/general.intro') }}</p>

        <table class="table" style="margin-bottom: 15px;">
            <tbody>
                <tr>
                    <th style="width: 180px; border-top: 0;">{{ trans('admin/access-audit/general.audited_page') }}</th>
                    <td style="border-top: 0;"><code>{{ $path }}</code> &nbsp;<span class="text-muted">{{ $label }}</span></td>
                </tr>
                <tr>
                    <th>{{ trans('admin/access-audit/general.gated_by') }}</th>
                    <td>
                        @foreach ($abilities as $ability)
                            <code>{{ $ability['ability'] }}@if ($ability['arguments'])({{ implode(', ', array_map(fn ($a) => class_basename($a), $ability['arguments'])) }})@endif</code>@if (! $loop->last), @endif
                        @endforeach
                    </td>
                </tr>
            </tbody>
        </table>

        <h3 style="margin: 0 0 5px;">
            {{ trans_choice('admin/access-audit/general.people_count', $total, ['count' => $total]) }}
        </h3>
        <p class="text-muted" style="margin-bottom: 0;">
            {{ trans('admin/access-audit/general.access_paths') }}:
            <strong>{{ $groups->count() }}</strong> {{ strtolower(trans('admin/access-audit/general.path_group')) }}
            &middot; <strong>{{ $departments->count() }}</strong> {{ strtolower(trans('admin/access-audit/general.path_department')) }}
            &middot; <strong>{{ $individuals->count() }}</strong> {{ strtolower(trans('admin/access-audit/general.path_individual')) }}
            &middot; <strong>{{ $superusers->count() }}</strong> {{ strtolower(trans('admin/access-audit/general.path_superuser')) }}
        </p>
    </x-box>


    <x-box>
        <h3 style="margin-top: 0;">{{ trans('admin/access-audit/general.groups_header') }}</h3>
        <p class="text-muted">{{ trans('admin/access-audit/general.groups_help') }}</p>

        @forelse ($groups as $entry)
            <h4 style="margin-bottom: 5px;">
                {{ $entry['group']->name }}
                <span class="text-muted" style="font-weight: normal;">&middot; {{ $entry['members']->count() }} {{ strtolower(trans('admin/access-audit/general.members')) }}</span>
                @if ($canOpenGroups)
                    <a href="{{ route('groups.edit', $entry['group']->id) }}" target="_blank" rel="noopener" class="btn btn-xs btn-default">{{ trans('admin/access-audit/general.open_group') }}</a>
                @endif
            </h4>
            <p style="margin-bottom: 20px;">
                @forelse ($entry['members'] as $member)
                    @if ($canOpenUsers)<a href="{{ route('users.show', $member->id) }}" target="_blank" rel="noopener">{{ $member->display_name }}</a>@else{{ $member->display_name }}@endif @if (! $loop->last), @endif
                @empty
                    <span class="text-muted">{{ trans('admin/access-audit/general.no_members') }}</span>
                @endforelse
            </p>
        @empty
            <p class="text-muted">{{ trans('admin/access-audit/general.none') }}</p>
        @endforelse
    </x-box>

    @if ($departments->isNotEmpty())
        <x-box>
            <h3 style="margin-top: 0;">{{ trans('admin/access-audit/general.departments_header') }}</h3>
            <p class="text-muted">{{ trans('admin/access-audit/general.departments_help') }}</p>

            @foreach ($departments as $entry)
                <h4 style="margin-bottom: 5px;">
                    {{ $entry['department']->name }}
                    <span class="text-muted" style="font-weight: normal;">&middot; {{ $entry['members']->count() }} {{ strtolower(trans('admin/access-audit/general.members')) }}</span>
                </h4>
                <p style="margin-bottom: 20px;">
                    @forelse ($entry['members'] as $member)
                        @if ($canOpenUsers)<a href="{{ route('users.show', $member->id) }}" target="_blank" rel="noopener">{{ $member->display_name }}</a>@else{{ $member->display_name }}@endif @if (! $loop->last), @endif
                    @empty
                        <span class="text-muted">{{ trans('admin/access-audit/general.no_members') }}</span>
                    @endforelse
                </p>
            @endforeach
        </x-box>
    @endif

    <x-box>
        <h3 style="margin-top: 0;">{{ trans('admin/access-audit/general.individuals_header') }}</h3>
        <p class="text-muted">{{ trans('admin/access-audit/general.individuals_help') }}</p>

        @forelse ($individuals as $user)
            <p style="margin-bottom: 5px;">
                @if ($canOpenUsers)<a href="{{ route('users.show', $user->id) }}" target="_blank" rel="noopener">{{ $user->display_name }}</a>@else{{ $user->display_name }}@endif
                <span class="text-muted">{{ $user->email }}</span>
            </p>
        @empty
            <p class="text-muted" style="margin-bottom: 0;">{{ trans('admin/access-audit/general.individuals_empty') }}</p>
        @endforelse
    </x-box>

    <x-box>
        {{-- A roster is only useful if you can find one name in it. The
             filter is client-side over the rows already rendered: the whole
             answer is on the page, so a keystroke should never cost a
             round trip. --}}
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 12px;">
            <h3 style="margin: 0;">
                {{ trans('admin/access-audit/general.everyone_header') }}
                <span id="access-audit-count" class="text-muted" style="font-size: 14px; font-weight: normal;"></span>
            </h3>
            <input type="search"
                   id="access-audit-filter"
                   class="form-control"
                   style="width: 280px; max-width: 100%;"
                   autocomplete="off"
                   aria-controls="access-audit-people"
                   placeholder="{{ trans('admin/access-audit/general.filter_placeholder') }}">
        </div>

        <div class="table-responsive">
            <table class="table table-striped table-hover" id="access-audit-people">
                <thead>
                    <tr>
                        <th>{{ trans('admin/access-audit/general.col_name') }}</th>
                        <th>{{ trans('admin/access-audit/general.col_email') }}</th>
                        <th>{{ trans('admin/access-audit/general.col_department') }}</th>
                        <th>{{ trans('admin/access-audit/general.col_how') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($people as $person)
                        <tr>
                            <td>
                                @if ($canOpenUsers)
                                    <a href="{{ route('users.show', $person['user']->id) }}" target="_blank" rel="noopener">{{ $person['user']->display_name }}</a>
                                @else
                                    {{ $person['user']->display_name }}
                                @endif
                            </td>
                            <td>{{ $person['user']->email }}</td>
                            <td>{{ optional($person['user']->department)->name }}</td>
                            <td>@include('access-audit._sources', ['sources' => $person['sources']])</td>
                        </tr>
                    @empty
                        <tr data-audit-static>
                            <td colspan="4" class="text-muted">{{ trans('admin/access-audit/general.nobody') }}</td>
                        </tr>
                    @endforelse
                    <tr data-audit-static data-audit-no-match style="display: none;">
                        <td colspan="4" class="text-muted">{{ trans('admin/access-audit/general.filter_no_match') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="text-muted" style="margin-bottom: 0;">{{ trans('admin/access-audit/general.review_prompt') }}</p>
    </x-box>

    <script nonce="{{ csrf_token() }}">
    (function () {
        var input = document.getElementById('access-audit-filter');
        var table = document.getElementById('access-audit-people');
        if (!input || !table || !table.tBodies.length) { return; }

        var rows = Array.prototype.filter.call(table.tBodies[0].rows, function (row) {
            return !row.hasAttribute('data-audit-static');
        });
        var noMatch = table.querySelector('[data-audit-no-match]');
        var count = document.getElementById('access-audit-count');
        var total = rows.length;

        function apply() {
            var query = input.value.trim().toLowerCase();
            var shown = 0;

            rows.forEach(function (row) {
                var hit = query === '' || row.textContent.toLowerCase().indexOf(query) !== -1;
                row.style.display = hit ? '' : 'none';
                if (hit) { shown++; }
            });

            if (noMatch) {
                noMatch.style.display = (total > 0 && shown === 0) ? '' : 'none';
            }
            if (count) {
                count.textContent = (query === '' || shown === total)
                    ? ''
                    : '{{ trans('admin/access-audit/general.filter_showing', ['shown' => '__SHOWN__', 'total' => '__TOTAL__']) }}'
                        .replace('__SHOWN__', shown).replace('__TOTAL__', total);
            }
        }

        input.addEventListener('input', apply);
        // Escape clears rather than closing anything: the lightbox listens on
        // the parent document, so the key never reaches it from in here.
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && input.value !== '') {
                e.preventDefault();
                input.value = '';
                apply();
            }
        });
    })();
    </script>

    <x-box>
        <h3 style="margin-top: 0;">{{ trans('admin/access-audit/general.superusers_header') }}</h3>
        <p class="text-muted">{{ trans('admin/access-audit/general.superusers_help') }}</p>

        @forelse ($superusers as $person)
            <p style="margin-bottom: 5px;">
                @if ($canOpenUsers)<a href="{{ route('users.show', $person['user']->id) }}" target="_blank" rel="noopener">{{ $person['user']->display_name }}</a>@else{{ $person['user']->display_name }}@endif
                <span class="text-muted">{{ $person['user']->email }}</span>
            </p>
        @empty
            <p class="text-muted" style="margin-bottom: 0;">{{ trans('admin/access-audit/general.none') }}</p>
        @endforelse
    </x-box>

</x-container>
@stop

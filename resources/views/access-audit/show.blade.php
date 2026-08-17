@extends('layouts/default')

@section('title')
    {{ trans('admin/access-audit/general.title') }} @parent
@stop

@section('header_right')
    <a href="{{ route('groups.audit') }}" target="_blank" rel="noopener" class="btn btn-sm btn-default">
        <i class="fas fa-table" aria-hidden="true"></i> {{ trans('admin/access-audit/general.full_matrix') }}
    </a>
@stop

@section('content')
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
        <h3 style="margin-top: 0;">{{ trans('admin/access-audit/general.everyone_header') }}</h3>

        <div class="table-responsive">
            <table class="table table-striped table-hover">
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
                                <a href="{{ route('users.show', $person['user']->id) }}" target="_blank" rel="noopener">{{ $person['user']->display_name }}</a>
                            </td>
                            <td>{{ $person['user']->email }}</td>
                            <td>{{ optional($person['user']->department)->name }}</td>
                            <td>@include('access-audit._sources', ['sources' => $person['sources']])</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-muted">{{ trans('admin/access-audit/general.nobody') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <p class="text-muted" style="margin-bottom: 0;">{{ trans('admin/access-audit/general.review_prompt') }}</p>
    </x-box>

    <x-box>
        <h3 style="margin-top: 0;">{{ trans('admin/access-audit/general.groups_header') }}</h3>
        <p class="text-muted">{{ trans('admin/access-audit/general.groups_help') }}</p>

        @forelse ($groups as $entry)
            <h4 style="margin-bottom: 5px;">
                {{ $entry['group']->name }}
                <span class="text-muted" style="font-weight: normal;">&middot; {{ $entry['members']->count() }} {{ strtolower(trans('admin/access-audit/general.members')) }}</span>
                <a href="{{ route('groups.edit', $entry['group']->id) }}" target="_blank" rel="noopener" class="btn btn-xs btn-default">{{ trans('admin/access-audit/general.open_group') }}</a>
            </h4>
            <p style="margin-bottom: 20px;">
                @forelse ($entry['members'] as $member)
                    <a href="{{ route('users.show', $member->id) }}" target="_blank" rel="noopener">{{ $member->display_name }}</a>@if (! $loop->last), @endif
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
                        <a href="{{ route('users.show', $member->id) }}" target="_blank" rel="noopener">{{ $member->display_name }}</a>@if (! $loop->last), @endif
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
                <a href="{{ route('users.show', $user->id) }}" target="_blank" rel="noopener">{{ $user->display_name }}</a>
                <span class="text-muted">{{ $user->email }}</span>
            </p>
        @empty
            <p class="text-muted" style="margin-bottom: 0;">{{ trans('admin/access-audit/general.individuals_empty') }}</p>
        @endforelse
    </x-box>

    <x-box>
        <h3 style="margin-top: 0;">{{ trans('admin/access-audit/general.superusers_header') }}</h3>
        <p class="text-muted">{{ trans('admin/access-audit/general.superusers_help') }}</p>

        @forelse ($superusers as $person)
            <p style="margin-bottom: 5px;">
                <a href="{{ route('users.show', $person['user']->id) }}" target="_blank" rel="noopener">{{ $person['user']->display_name }}</a>
                <span class="text-muted">{{ $person['user']->email }}</span>
            </p>
        @empty
            <p class="text-muted" style="margin-bottom: 0;">{{ trans('admin/access-audit/general.none') }}</p>
        @endforelse
    </x-box>

</x-container>
@stop

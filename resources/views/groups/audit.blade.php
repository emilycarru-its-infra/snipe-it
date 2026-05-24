@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ trans('admin/groups/general.audit_title') }}
    @parent
@stop

{{-- Page content --}}
@section('content')
    <x-container>

        {{-- Summary callout --}}
        <div class="callout callout-info">
            <p>{{ trans('admin/groups/general.audit_help') }}</p>
            <p class="text-muted" style="margin-bottom: 0;">
                <strong>{{ trans_choice('admin/groups/general.audit_group_count', $groups->count(), ['count' => $groups->count()]) }}</strong>
                &middot;
                <a href="#users-without-group">
                    <strong>{{ trans_choice('admin/groups/general.audit_users_without_group_count', $usersWithoutGroupCount, ['count' => $usersWithoutGroupCount]) }}</strong>
                </a>
            </p>
        </div>

        {{-- Groups × permissions matrix, one table per Snipe section --}}
        @foreach ($sections as $section)
            <x-box>
                <h3 style="margin-top: 0;">{{ $section['name'] }}</h3>

                <div class="table-responsive" style="overflow-x: auto;">
                    <table class="table table-striped table-hover" style="white-space: nowrap;">
                        <thead>
                            <tr>
                                <th style="min-width: 200px; position: sticky; left: 0; background: #fff; z-index: 2;">
                                    {{ trans('admin/groups/titles.group_name') }}
                                </th>
                                <th style="text-align: center;">{{ trans('general.users') }}</th>
                                @foreach ($section['permissions'] as $perm)
                                    <th style="text-align: center;" data-tooltip="true" title="{{ $perm['permission'] }}">
                                        {{ trans('permissions.'.str_slug($perm['permission']).'.name') }}
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($groups as $group)
                                <tr>
                                    <td style="position: sticky; left: 0; background: inherit; z-index: 1;">
                                        <a href="{{ route('groups.edit', $group['id']) }}">{{ $group['name'] }}</a>
                                    </td>
                                    <td style="text-align: center;">
                                        @if ($group['users_count'] > 0)
                                            <a href="{{ route('users.index', ['group_id' => $group['id']]) }}">{{ $group['users_count'] }}</a>
                                        @else
                                            <span class="text-muted">0</span>
                                        @endif
                                    </td>
                                    @foreach ($section['permissions'] as $perm)
                                        @php
                                            $value = $group['permissions'][$perm['permission']] ?? '0';
                                        @endphp
                                        <td style="text-align: center;">
                                            @if ((string) $value === '1')
                                                <i class="fas fa-check text-success" aria-hidden="true" data-tooltip="true" title="{{ trans('admin/groups/general.audit_granted') }}"></i>
                                                <span class="sr-only">{{ trans('admin/groups/general.audit_granted') }}</span>
                                            @elseif ((string) $value === '-1')
                                                <i class="fas fa-times text-danger" aria-hidden="true" data-tooltip="true" title="{{ trans('admin/groups/general.audit_denied') }}"></i>
                                                <span class="sr-only">{{ trans('admin/groups/general.audit_denied') }}</span>
                                            @else
                                                <span class="text-muted">&mdash;</span>
                                                <span class="sr-only">{{ trans('admin/groups/general.audit_inherit') }}</span>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ count($section['permissions']) + 2 }}" class="text-muted text-center">
                                        {{ trans('admin/groups/general.audit_no_groups') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-box>
        @endforeach

        {{-- Users not in any group --}}
        <x-box id="users-without-group">
            <h3 style="margin-top: 0;">{{ trans('admin/groups/general.audit_users_without_group_header') }}</h3>
            <p class="text-muted">{{ trans('admin/groups/general.audit_users_without_group_help') }}</p>

            @if ($usersWithoutGroupCount === 0)
                <p class="text-success"><i class="fas fa-check" aria-hidden="true"></i> {{ trans('admin/groups/general.audit_users_without_group_empty') }}</p>
            @else
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>{{ trans('general.name') }}</th>
                                <th>{{ trans('general.username') }}</th>
                                <th>{{ trans('general.email') }}</th>
                                <th style="text-align: center;">{{ trans('admin/groups/general.audit_has_individual') }}</th>
                                <th style="text-align: center;">{{ trans('admin/users/table.last_login') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($usersWithoutGroup as $user)
                                <tr>
                                    <td>
                                        <a href="{{ route('users.show', $user->id) }}">{{ $user->display_name }}</a>
                                    </td>
                                    <td>{{ $user->username }}</td>
                                    <td>{{ $user->email }}</td>
                                    <td style="text-align: center;">
                                        @if ($user->hasIndividualPermissions())
                                            <span class="label label-warning" data-tooltip="true" title="{{ trans('admin/users/general.individual_override') }}">
                                                <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
                                                {{ trans('general.yes') }}
                                            </span>
                                        @else
                                            <span class="text-muted">{{ trans('general.no') }}</span>
                                        @endif
                                    </td>
                                    <td style="text-align: center;">
                                        @if ($user->last_login)
                                            <span data-tooltip="true" title="{{ $user->last_login }}">{{ \Carbon\Carbon::parse($user->last_login)->diffForHumans() }}</span>
                                        @else
                                            <span class="text-muted">{{ trans('general.no') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-box>

    </x-container>
@stop

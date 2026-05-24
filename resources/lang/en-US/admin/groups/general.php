<?php

return [
    'audit_title' => 'Permissions Audit',
    'audit_help' => 'A read-only matrix of every permission group\'s grants and denies across every available permission, alongside any users who are not currently in any group. Use this to verify the org-wide access surface at a glance.',
    'audit_group_count' => '{0}No groups defined|{1}:count group|[2,*]:count groups',
    'audit_users_without_group_count' => '{0}No users without a group|{1}:count user without a group|[2,*]:count users without a group',
    'audit_granted' => 'Granted',
    'audit_denied' => 'Denied',
    'audit_inherit' => 'Inherit / not set',
    'audit_no_groups' => 'No groups defined yet.',
    'audit_users_without_group_header' => 'Users not in any group',
    'audit_users_without_group_help' => 'These activated users have no group membership. Anything they can do comes from individual permission overrides on their user record — usually a sign of ad-hoc setup that should be moved into a group.',
    'audit_users_without_group_empty' => 'Every activated user belongs to at least one group.',
    'audit_has_individual' => 'Individual perms',
];

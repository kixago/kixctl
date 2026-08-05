<?php

return [
    'singular' => 'User',
    'plural' => 'Users',

    'table' => [
        'name' => 'Name',
        'email' => 'Email',
        'groups' => 'Groups',
        'no_group' => 'No group',
        'created_at' => 'Created',
    ],

    'form' => [
        'section_identity' => 'Identity',
        'section_identity_help' => 'The person’s name, sign-in email, and password.',
        'name' => 'Name',
        'email' => 'Email',
        'password' => 'Password',
        'password_help' => 'Leave blank when editing to keep the current password.',

        'section_groups' => 'Group membership',
        'section_groups_help' => 'A group grants a bundle of permissions. A user receives every permission of every group they belong to.',
        'groups' => 'Groups',
        'groups_help' => 'The groups this user belongs to. Leave empty for the lowest-access default.',

        'section_direct' => 'Individual permissions',
        'section_direct_help' => 'Grants given to this user alone, on top of their groups — for one-off exceptions a group doesn’t cover.',
        'direct_permissions' => 'Direct permissions',
        'direct_permissions_help' => 'Extra permissions for this user beyond what their groups already allow.',
    ],
];

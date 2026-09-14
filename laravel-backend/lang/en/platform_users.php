<?php
return [
    'nav' => 'Platform Users',
    'singular' => 'Platform User',

    'section_identity' => 'Identity',
    'section_contact' => 'Contact',
    'section_role' => 'Role and status',

    'name' => 'Name',
    'first_name' => 'First name',
    'last_name' => 'Last name',
    'avatar' => 'Avatar URL',
    'display_id' => 'Display ID',
    'display_id_help' => 'What the customer sees on a ticket reply — e.g. "Support 1". Falls back to the real name if empty.',

    'role' => 'Platform role',
    'role_support' => 'Support',
    'role_admin' => 'Admin',
    'role_help' => 'Entirely separate from a user\'s role inside a tenant. Support cannot reach platform finances, keys, settings or the staff list.',
    'started_at' => 'Start date',
    'is_active' => 'Active',
    'is_active_help' => 'Deactivating cuts access immediately — from their very next request, even if still logged in.',
    'last_login' => 'Last login',

    'activate' => 'Activate',
    'deactivate' => 'Deactivate',
];

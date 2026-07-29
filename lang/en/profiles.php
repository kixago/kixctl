<?php

return [

    'title' => 'Profiles',

    'table' => [
        'heading' => 'Profiles',
        'key' => 'Key',
        'label' => 'Label',
        'pool' => 'Root pool',
        'auto' => 'auto',
        'devices' => 'Devices',
        'default' => 'Default',
        'used_by' => 'In use',
        'managed' => 'Managed',
        'locked' => 'Locked',
        'none' => '—',
    ],

    'form' => [
        'key' => 'Key',
        'key_help' => 'Slug and Incus profile name (e.g. kixweb, workers). Letters, numbers, dashes.',
        'existing' => 'Existing profile',
        'existing_help' => 'A profile already on your cluster (e.g. default). kixctl references it as a target but never creates, edits, or deletes it.',
        'label' => 'Label',
        'description' => 'Description',
        'description_help' => 'Written to the Incus profile description. Blank falls back to the label.',
        'pool' => 'Root-disk pool',
        'pool_placeholder' => 'auto (resolve from the cluster)',
        'pool_help' => 'Leave blank to let kixctl pick a pool from the cluster. Choose one to pin the root disk to a specific pool.',
        'pool_locked_help' => 'The root-disk pool is fixed while instances inherit this profile. It can only be changed when nothing is using the profile.',
        'pool_unmanaged_help' => "This is the profile's live root-disk pool. kixctl references it and never changes it.",
        'nic' => 'Attach eth0 to a network',
        'nic_placeholder' => 'root-disk only (no NIC)',
        'nic_help' => 'Optional. Attach an eth0 NIC on a network, or leave blank for a root-disk-only profile — placement is normally the per-instance network, not the profile.',
        'is_default' => 'Make this the default profile',
        'key_locked_help' => "A profile's key can't be changed. Delete and recreate to rename.",
        'owned_by_profile' => 'Set by the profile itself — kixctl references this and never changes it.',
    ],

    'crud' => [
        'create' => 'Create profile',
        'created' => 'Profile created.',
        'create_failed' => 'Could not create profile',
        'register' => 'Register existing',
        'registered' => 'Profile registered.',
        'register_failed' => 'Could not register profile',
        'edit' => 'Edit',
        'updated' => 'Profile updated.',
        'update_failed' => 'Could not update profile',
        'delete' => 'Delete',
        'deleted' => 'Profile deleted.',
        'delete_failed' => 'Could not delete profile',
        'delete_confirm' => 'This deletes the profile and removes it from Incus. Move any instances off it first.',
        'deregister' => 'Deregister',
        'deregistered' => 'Reference removed. The profile was not touched.',
        'deregister_failed' => 'Could not deregister profile',
        'deregister_confirm' => "This removes only kixctl's reference to :key. The :key profile and everything inheriting it are left completely untouched.",
        'set_default' => 'Set default',
        'default_set' => 'Default profile updated.',
        'defaults' => 'Back to defaults',
        'defaults_confirm' => 'Remove kixctl-created profiles and re-assert kix as the default. Your registered (unmanaged) profiles and the locked default are left alone. Profiles currently in use are skipped.',
        'defaults_title' => 'Profiles reset',
        'defaults_done' => 'Removed: :removed. kix is the default again.',
        'defaults_skipped' => 'Skipped (in use): :skipped',
    ],

    'empty' => [
        'heading' => 'No profiles yet',
        'description' => 'kixctl seeds the locked kix profile. Create your own, or register one you already run.',
    ],

];

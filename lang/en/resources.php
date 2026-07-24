<?php

return [
    'title' => 'Resources',
    'tabs' => [
        'volumes' => 'Volumes',
        'networks' => 'Networks',
        'profiles' => 'Profiles',
        'pools' => 'Pools',
    ],
    'notice' => [
        'summary_volumes' => 'Storage pools and volumes are not shown for this cluster.',
        'summary_networks' => 'Networks are not shown for this cluster.',
        'summary_profiles' => 'Profiles are not shown for this cluster.',
        'summary_pools' => 'Storage pools are not shown for this cluster.',
        'restricted_cert_cause' => 'The server declined the request: :reason. This cluster runs Incus :version, which does not permit a restricted certificate to view storage information. Incus 7 and later provide a filtered view instead. This data becomes available if the cluster\'s administrator upgrades Incus, or grants the Kixctl certificate:fingerprint unrestricted access in the cluster\'s trust settings. Unrestricted access allows Kixctl to manage everything on that server, so that decision belongs to the administrator. Kixctl cannot raise its own level of access.',
        'declined_reason' => 'The server declined the request: :reason.',
    ],
    'volumes' => [
        'types' => [
            'custom' => 'custom volumes',
            'container' => 'container root disks',
            'virtual_machine' => 'VM root disks',
            'image' => 'image cache',
        ],
        'columns' => [
            'name' => 'Name',
            'pool' => 'Pool',
            'type' => 'Type',
            'content_type' => 'Content',
            'node' => 'Node',
            'cluster' => 'Cluster',
            'used_by' => 'Used by',
        ],
        'empty_state_custom' => 'No custom volumes yet. The other volume types are instance root disks and cached images, which Incus manages through their instances. Creating a custom volume, an attachable data disk, will be the first management action on this page.',
        'actions' => [
            'create' => 'Create custom volume',
            'delete' => 'Delete volume',
        ],
        'create' => [
            'cluster_label' => 'Cluster',
            'pool_label' => 'Storage pool',
            'name_label' => 'Volume name',
            'desc_label' => 'Description',
            'name_regex' => 'Name may only contain letters, digits, and hyphens.',
            'success' => 'Volume created',
            'failed' => 'Create failed',
        ],
        'delete' => [
            'heading' => 'Delete custom volume',
            'description' => 'This permanently deletes the custom volume “:name” from pool “:pool”. This cannot be undone. Are you absolutely sure?',
            'success' => 'Volume deleted',
            'failed' => 'Delete failed',
        ],
    ],
    'networks' => [
        'managed' => 'managed',
        'observed' => 'observed',
        'columns' => [
            'name' => 'Name',
            'type' => 'Type',
            'managed' => 'Managed',
            'cluster' => 'Cluster',
            'used_by' => 'Used by',
        ],
    ],
    'profiles' => [
        'shared_widely' => '⚠ shared widely',
        'shared_widely_tooltip' => ':count instances inherit this profile; editing it changes all of them',
        'columns' => [
            'name' => 'Name',
            'description' => 'Description',
            'devices' => 'Devices',
            'cluster' => 'Cluster',
            'used_by' => 'Used by',
        ],
    ],
    'pools' => [
        'columns' => [
            'name' => 'Name',
            'driver' => 'Driver',
            'status' => 'Status',
            'cluster' => 'Cluster',
            'used_by' => 'Used by',
        ],
    ],
    'phrases' => [
        'readonly_footer' => 'Management actions arrive per resource, each behind its own permission.',
        'search_placeholder' => 'Search :tab…',
    ],
];

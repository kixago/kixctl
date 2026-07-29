<?php

return [

    'title' => 'Networks',

    'table' => [
        'heading' => 'Networks',
        'key' => 'Key',
        'label' => 'Label',
        'subnet' => 'Subnet',
        'auto' => 'auto',
        'nat' => 'NAT',
        'dhcp' => 'DHCP',
        'isolation' => 'Isolation',
        'default' => 'Default',
        'used_by' => 'In use',
        'managed' => 'Managed',
        'locked' => 'Locked',
    ],

    'form' => [
        'key' => 'Key',
        'key_help' => 'Slug and Incus bridge name (e.g. kixbr1, workbr0). Letters, numbers, dashes.',
        'label' => 'Label',
        'description' => 'Description',
        'description_help' => 'Written to the Incus network description. Blank falls back to the label.',
        'cidr' => 'IPv4 CIDR',
        'cidr_help' => 'Leave blank to let Incus auto-assign an unused private subnet. Pin e.g. 10.50.0.1/24 to choose your own.',
        'nat' => 'NAT (internet egress)',
        'dhcp' => 'Managed DHCP',
        'isolation' => 'Isolation',
        'is_default' => 'Make this the default network',
        'key_locked_help' => "A network's key can't be changed. Delete and recreate to rename.",
        'cidr_locked_help' => 'The subnet is fixed after creation. Delete and recreate to change it.',
    ],

    'crud' => [
        'create' => 'Create network',
        'created' => 'Network created.',
        'create_failed' => 'Could not create network',
        'edit' => 'Edit',
        'updated' => 'Network updated.',
        'update_failed' => 'Could not update network',
        'delete' => 'Delete',
        'deleted' => 'Network deleted.',
        'delete_failed' => 'Could not delete network',
        'set_default' => 'Set default',
        'default_set' => 'Default network updated.',
    ],

    'resolver' => [
        'heading' => 'DNS resolver',
        'absent' => 'Resolver not created yet.',
        'absent_help' => 'kixctl will create its managed bridge (kixbr0), its own profile (kix), and the CoreDNS resolver that rides them. Nothing of your existing setup is touched.',
        'provisioning' => 'Provisioning the resolver…',
        'ready' => 'Resolver running.',
    ],

    'action' => [
        'create' => 'Create resolver',
        'rebuild' => 'Rebuild resolver',
        'rebuild_confirm' => 'Delete the current resolver and provision a fresh one. Use this after a flake change or to recover a broken resolver. DNS is briefly unavailable during the rebuild.',
    ],

    'console' => [
        'heading' => 'Build console',
        'waiting' => 'Waiting for output…',
        'show' => 'Show console',
        'hide' => 'Hide console',
        'unavailable' => 'Live output unavailable (Reverb not connected).',
    ],

    'toast' => [
        'title' => 'Provisioning network',
        'pending' => 'Starting…',
        'ensuring' => 'Creating the managed bridge…',
        'profile' => 'Creating the kix profile…',
        'building' => 'Building the resolver image…',
        'importing' => 'Importing the image…',
        'launching' => 'Launching the resolver…',
        'starting' => 'Starting the resolver…',
        'leasing' => 'Waiting for a DHCP lease…',
        'serving' => 'Serving.',
        'done' => 'Network ready.',
        'failed' => 'Provisioning failed.',
        'unavailable' => 'Live updates unavailable (Reverb not connected).',
    ],

];

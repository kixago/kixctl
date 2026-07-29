<?php

return [

    'title' => 'Networks',

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

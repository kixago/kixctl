<?php

return [

    'table' => [
        'heading' => 'Pools',
        'description' => 'Groups of apps that promote together, so a single Update promotes the whole pool at once. Create a pool here, then assign apps to it on the Repositories tab.',
        'pool' => 'Pool',
        'name' => 'Name',
        'apps' => 'Apps',
    ],

    'form' => [
        'label' => 'Pool name',
        'label_placeholder' => 'Production web apps',
        'label_help' => 'The display name for this pool. A stable internal name is derived from it and does not change when you rename the pool.',
    ],

    'crud' => [
        'add' => 'Add pool',
        'added' => 'Pool added.',
        'add_failed' => 'The pool could not be added.',
        'edit' => 'Edit',
        'updated' => 'Pool updated.',
        'update_failed' => 'The pool could not be updated.',
        'delete' => 'Remove',
        'delete_heading' => 'Remove pool “:pool”?',
        'delete_empty' => 'This removes the pool. It cannot be undone.',
        'delete_members' => '{1} This pool still has one app attached: :apps. Removing the pool returns that app to promoting individually — the app itself is not deleted. Cancel to move it first, or confirm to remove the pool.|[2,*] This pool still has :count apps attached: :apps. Removing the pool returns those apps to promoting individually — the apps themselves are not deleted. Cancel to move them first, or confirm to remove the pool.',
        'deleted' => 'Pool removed.',
    ],

];

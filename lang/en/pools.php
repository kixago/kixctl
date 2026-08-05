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
        'delete_members' => '{1} This pool still has one app attached. Removing the pool returns that app to promoting individually — the app itself is not deleted. Cancel to move it first, or confirm to remove the pool.|[2,*] This pool still has :count apps attached. Removing the pool returns those apps to promoting individually — the apps themselves are not deleted. Cancel to move them first, or confirm to remove the pool.',
        'deleted' => 'Pool removed.',
    ],

    // Update all: one action promotes every member of a pool that has a revision
    // ready, reusing the per-app cutover. Results are reported per member — a
    // partial batch is a valid state, never a single pass/fail.
    'promote' => [
        'pool_gone' => 'That pool no longer exists.',
        'member_failed' => ':app could not be promoted: :reason',
        'summary_title' => 'Updated pool “:pool”',
        'summary_title_failed' => 'Pool “:pool” updated with failures',
        'summary_none' => 'Nothing to promote — every app in this pool is already current.',
        'summary' => 'Promoted :promoted of :total.',
        'summary_skipped' => ':count already current.',
        'summary_failed' => 'Failed — :list.',
    ],

    // The Updates tab surface (P3-7): pools with a member ready to promote, and the
    // one Update all that promotes the whole batch.
    'updates' => [
        'heading' => 'Pools ready to promote',
        'intro' => 'These pools have apps with a newer revision waiting. Update all promotes every ready app in the pool at once; apps already current are skipped.',
        'ready_count' => '{1} :count app ready|[2,*] :count apps ready',
        'update_all' => 'Update all',
        'update_all_heading' => 'Promote every ready app in this pool?',
        'update_all_confirm' => '{1} This promotes :count app in “:pool” to its newest revision. The previous revision is kept so you can revert, and any app already current is skipped.|[2,*] This promotes :count apps in “:pool” to their newest revisions. Each previous revision is kept so you can revert, and any app already current is skipped.',
        'queued' => 'Update all queued for “:pool”. Apps will promote and report as they go.',
    ],

];

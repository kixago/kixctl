<?php

return [

    'heading' => 'Updates',
    'intro' => 'Each deployed app and its revisions. A push builds a new revision alongside the one that is running; promote it when you are ready, revert to an earlier one, or remove revisions you no longer need.',
    'history' => 'Previous revisions',
    'live' => 'Live',
    'not_running' => 'stopped',
    'none' => 'nothing eligible',
    'reaped' => 'Old revisions removed',
    'reap_failed' => 'Could not remove the revisions',

    'empty' => [
        'heading' => 'No deployed apps yet',
        'description' => 'Push to a connected repository and its first revision goes live here.',
    ],

    'ready' => [
        'heading' => 'A newer revision is ready to promote',
    ],

    'state' => [
        'reap_eligible' => 'ready to remove',
        'retired' => 'retired',
        'inactive' => 'inactive',
    ],

    'toast' => [
        'title' => 'Deploy lifecycle',
        'pending' => 'Working…',
        'done' => 'Done.',
        'failed' => 'Something went wrong.',
        'dismiss' => 'dismiss',
    ],

    'action' => [
        'cutover' => 'Update',
        'cutover_heading' => 'Promote this revision?',
        'cutover_confirm' => 'Live traffic moves to :instance. The current revision is kept and stopped, so you can revert to it.',

        'revert' => 'Revert',
        'revert_heading' => 'Revert to this revision?',
        'revert_confirm' => 'Live traffic moves back to :instance. The revision you are leaving is kept and stopped.',

        'reap' => 'Remove old revisions',
        'reap_heading' => 'Remove retired revisions?',
        'reap_confirm' => 'These revisions were retired past the keep window and will be deleted, along with their images: :list. The live revision is never removed.',
        'reap_error' => 'Could not read what is eligible right now: :error',
    ],

];

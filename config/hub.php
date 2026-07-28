<?php

/**
 * Oak Tree Venture Hub — product configuration.
 *
 * Values here are install-time defaults. Anything an administrator is allowed to
 * change at runtime is mirrored into the `settings` table (see App\Models\Setting)
 * and read through App\Support\HubSettings, which falls back to these.
 */
return [

    'emergency' => [
        // Minutes to wait for an acknowledgement before re-alerting.
        'escalation_interval_minutes' => (int) env('HUB_ESCALATION_INTERVAL', 2),

        // After this many re-alerts the recipient is reported as unreachable
        // and the chase stops, rather than looping forever.
        'max_escalations' => (int) env('HUB_MAX_ESCALATIONS', 5),

        // Rate limits for non-admin senders. The flag only stays meaningful if
        // it is scarce.
        'rate_limit' => [
            'per_window' => (int) env('HUB_EMERGENCY_PER_WINDOW', 1),
            'window_minutes' => (int) env('HUB_EMERGENCY_WINDOW_MINUTES', 5),
            'per_day' => (int) env('HUB_EMERGENCY_PER_DAY', 8),
        ],

        // Sending more than this in a rolling week puts a sender on the
        // admin misuse report.
        'misuse_threshold_per_week' => (int) env('HUB_MISUSE_THRESHOLD', 5),
    ],

    'attachments' => [
        'disk' => env('HUB_ATTACHMENT_DISK', 'attachments'),
        'max_kilobytes' => (int) env('HUB_ATTACHMENT_MAX_KB', 10240),
        // Allow-list, not a deny-list. Anything not named here is rejected.
        'allowed_mimes' => [
            'jpg', 'jpeg', 'png', 'gif', 'webp',
            'pdf', 'txt', 'csv',
            'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        ],
    ],

    'avatar_disk' => env('HUB_AVATAR_DISK', 'avatars'),

    'messages' => [
        'page_size' => 40,
        'max_length' => 4000,
        'edit_window_minutes' => (int) env('HUB_EDIT_WINDOW_MINUTES', 60),
    ],

    'import' => [
        'chunk_size' => 200,
        'max_rows' => 2000,
    ],
];

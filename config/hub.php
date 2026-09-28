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

        /*
         * Raise this and you must raise config/livewire.php's
         * temporary_file_upload rule with it — that gate runs first, and a file
         * it rejects never reaches the validation in App\Livewire\Hub\Conversation.
         * Both read this same env var for that reason. nginx's
         * client_max_body_size and PHP's upload_max_filesize also have to allow
         * it; see the README.
         */
        'max_kilobytes' => (int) env('HUB_ATTACHMENT_MAX_KB', 40960),

        /*
         * A ceiling on the whole selection, not just one file.
         *
         * Livewire posts every chosen file in a single request, so five files at
         * the per-file limit would be one 200 MB POST — a request buffer any one
         * person could fill. Capping the batch keeps five attachments possible
         * while bounding what the web server has to hold: two at full size, or
         * five smaller ones.
         */
        'max_batch_kilobytes' => (int) env('HUB_ATTACHMENT_MAX_BATCH_KB', 81920),
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

    /*
     * 1:1 calling in direct messages. Media is peer-to-peer and never reaches
     * this server; the only things here are the ring lifecycle and the relay
     * credentials handed to the browser.
     */
    'calls' => [
        'enabled' => (bool) env('HUB_CALLS_ENABLED', true),

        // How long a caller rings before giving up. The callee's own timer is
        // deliberately a few seconds longer (see below) so their ringtone can
        // never outlive the caller's willingness to wait.
        'ring_seconds' => (int) env('HUB_CALL_RING_SECONDS', 35),
        'callee_ring_seconds' => (int) env('HUB_CALL_CALLEE_RING_SECONDS', 40),

        // If no live tab answers the presence probe in this long, the callee
        // has no browser open — a different failure from "did not pick up",
        // and worth a different message.
        'alert_grace_seconds' => (int) env('HUB_CALL_ALERT_GRACE_SECONDS', 6),

        // Ceilings on ICE. Without these a failed connection shows a spinner
        // forever, which is the worst possible answer.
        'connect_timeout_seconds' => (int) env('HUB_CALL_CONNECT_TIMEOUT_SECONDS', 20),
        'reconnect_grace_seconds' => (int) env('HUB_CALL_RECONNECT_GRACE_SECONDS', 10),

        // TURN credentials are minted per call and held only in memory. An hour
        // covers a long call plus one mid-call ICE restart with fresh keys.
        'credential_ttl_seconds' => (int) env('HUB_CALL_CREDENTIAL_TTL', 3600),

        // Guard rail below Reverb's max_message_size. A video renegotiation
        // offer that would exceed the socket's limit is refused locally, so the
        // audio call survives rather than the connection being closed.
        'max_whisper_bytes' => (int) env('HUB_CALL_MAX_WHISPER_BYTES', 28000),
    ],

    /*
     * Room huddles: many-to-many audio, video and screen share in ANY room,
     * including the system rooms that hold hundreds of members.
     *
     * Audio is published on joining; camera and screen share are opt-in from the
     * controls, which is why max_participants below is quoted in audio terms —
     * it is the floor of what a huddle costs, not the ceiling.
     *
     * The one structural difference from 'calls' above is the whole story: media
     * does NOT go peer-to-peer. Every stream passes through the LiveKit SFU, which
     * is why the participant cap and the kill switch below are real operational
     * levers rather than decoration — they are the only things standing between a
     * company-wide room and this server's bandwidth bill.
     *
     * Nothing here is persisted. Live state lives in the cache and in LiveKit's
     * own memory; when a huddle ends there is nothing left, exactly as with calls.
     * There is no recording: every token is minted with roomRecord false and no
     * Egress service is installed.
     *
     * A huddle does not ring anybody. Starting one puts a banner in the room and
     * nothing else, which is what makes it safe to allow in a 300-member room —
     * there is no availability or DND fan-out because there is nothing to silence.
     */
    'huddles' => [
        /*
         * Deliberately separate from calls.enabled, because the two fail
         * differently. A failing 1:1 call affects two people and costs nothing
         * but a relay allocation; a failing huddle takes down a meeting of
         * twenty. During a bandwidth incident an administrator needs to stop the
         * expensive thing without also taking away the free peer-to-peer one.
         */
        'enabled' => (bool) env('HUB_HUDDLES_ENABLED', true),

        // A huddle is N-squared, not N: everyone subscribes to everyone. At 30
        // participants on audio this is roughly 28 Mbps of egress from this box.
        // Raising it is a bandwidth-bill decision, not a preference.
        'max_participants' => (int) env('HUB_HUDDLE_MAX_PARTICIPANTS', 30),

        // Spent once, at join. LiveKit does not disconnect an established
        // session when this expires, so this only has to be long enough to grant
        // a microphone permission and find a headset — and short enough that a
        // leaked token is worthless by the time anyone could use it.
        'token_ttl_seconds' => (int) env('HUB_HUDDLE_TOKEN_TTL', 900),

        // Handed to LiveKit at room creation. The banner clears the moment the
        // last participant leaves, well before this fires — it only bounds the
        // mess when a webhook is lost.
        'empty_timeout_seconds' => (int) env('HUB_HUDDLE_EMPTY_TIMEOUT', 60),

        // empty_timeout only fires when a room is EMPTY. A forgotten tab left
        // connected overnight is billable egress and a permanent "Call in
        // progress" banner, so huddles:reconcile closes anything older than this.
        'max_duration_minutes' => (int) env('HUB_HUDDLE_MAX_DURATION_MINUTES', 240),

        // How long cached huddle state survives without a webhook. Longer than
        // max_duration_minutes on purpose, so cache expiry is never the thing
        // that ends a huddle.
        'state_ttl_minutes' => (int) env('HUB_HUDDLE_STATE_TTL_MINUTES', 300),

        /*
         * How many participants ride along in the broadcast payload.
         *
         * Same guard rail as max_whisper_bytes above and for the same reason: a
         * 200-person roster would exceed REVERB_APP_MAX_MESSAGE_SIZE, and Reverb
         * closes the connection rather than truncating. The banner never needs
         * more than a facepile, and the true count travels alongside.
         */
        'broadcast_participants' => (int) env('HUB_HUDDLE_BROADCAST_PARTICIPANTS', 8),

        /*
         * The entire mapping between a Room and a LiveKit room, in both
         * directions: "hub-room-{id}". Derived, never stored — which is what
         * makes "no tables" possible, and means two huddles cannot coexist in
         * one room by construction.
         *
         * Changing this on a running install orphans every live huddle.
         */
        'room_prefix' => env('HUB_HUDDLE_ROOM_PREFIX', 'hub-room-'),
    ],
];

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     * Cloudflare Realtime TURN, for the ~10-20% of calls that cannot establish
     * peer-to-peer through corporate NAT.
     *
     * Note the asymmetry with VAPID: there is NO public half here. Neither of
     * these values is ever exposed to the browser or given a VITE_ twin — the
     * client receives only short-lived credentials minted per call.
     */
    'cloudflare_turn' => [
        'key_id' => env('CLOUDFLARE_TURN_KEY_ID'),
        'api_token' => env('CLOUDFLARE_TURN_API_TOKEN'),
        'endpoint' => env('CLOUDFLARE_TURN_ENDPOINT', 'https://rtc.live.cloudflare.com/v1/turn/keys'),
    ],

    /*
     * Self-hosted LiveKit — the selective forwarding unit behind room huddles.
     *
     * The same asymmetry as cloudflare_turn above, and worse. That token could
     * cost you relay bandwidth; this secret signs BOTH the join tokens we hand
     * out AND the webhooks LiveKit sends back. Anyone holding it can join any
     * huddle in the hub as any employee, and can forge a "Call in progress"
     * banner in every room in the company. No VITE_ twin, no meta tag, ever.
     *
     * `url` is the one public value — and it still gets no VITE_ twin, because
     * the join endpoint hands it to the browser at join time. Keeping it out of
     * the bundle is what makes moving the SFU to its own box a DNS change rather
     * than a front-end rebuild.
     *
     * `api_url` is loopback: LiveKit binds 127.0.0.1 so its admin API — ListRooms,
     * RemoveParticipant, DeleteRoom — is never reachable from the internet.
     */
    'livekit' => [
        'url' => env('LIVEKIT_URL'),
        'api_url' => env('LIVEKIT_API_URL', 'http://127.0.0.1:7880'),
        'key' => env('LIVEKIT_API_KEY'),
        'secret' => env('LIVEKIT_API_SECRET'),
    ],

];

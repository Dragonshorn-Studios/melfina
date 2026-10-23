<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Messages Label
    |--------------------------------------------------------------------------
    |
    | Display name for the owner console's directed-messages screen. The
    | feature stays named "messages" internally — this is only the
    | proper-name slot used in nav, headings, and page titles. Trimmed and
    | fell back to the default by AgentMessage::messagesLabel(); rendered
    | escaped, never as HTML.
    |
    */

    'messages_label' => env('MELFINA_MESSAGES_LABEL', 'AgentPost'),

    /*
    |--------------------------------------------------------------------------
    | Version
    |--------------------------------------------------------------------------
    |
    | What GET /api/admin/status reports as this install's version — a
    | fleet system's connectivity check reads it to tell installs apart.
    | Self-hosted installs have no release channel, so the default is a
    | stable constant the deployer may override per environment.
    |
    */

    'version' => env('MELFINA_VERSION', 'self-hosted'),

    /*
    |--------------------------------------------------------------------------
    | Mail Listen Seconds
    |--------------------------------------------------------------------------
    |
    | How long a `subscriptions/listen` SSE stream stays open for mail pings
    | before closing and letting the client re-listen. Bound so a wedged
    | client can't pin a PHP worker forever.
    |
    */

    'mail_listen_seconds' => (int) env('MELFINA_MAIL_LISTEN_SECONDS', 110),

];

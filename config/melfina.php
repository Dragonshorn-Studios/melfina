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

];

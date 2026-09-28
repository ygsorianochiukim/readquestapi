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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'azure_speech' => [
        'key' => env('AZURE_SPEECH_KEY'),
        'region' => env('AZURE_SPEECH_REGION'),
        'voice' => env('AZURE_SPEECH_VOICE', 'en-US-JennyNeural'),
        // Seconds to wait for narration. A whole chapter in an HD voice takes a while.
        'timeout' => (int) env('AZURE_SPEECH_TIMEOUT', 180),
    ],

    'azure_vision' => [
        'key' => env('AZURE_VISION_KEY'),
        // e.g. https://<resource-name>.cognitiveservices.azure.com
        'endpoint' => env('AZURE_VISION_ENDPOINT'),
    ],

    // Reads uploaded PDFs as text. When a key is set it is used instead of
    // Azure Vision for PDFs, which Azure refuses over 4MB.
    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
        // The model that sorts story pages from credits, footers and exercises.
        // Defaults to the model above; a stronger one sorts more reliably.
        'agent_model' => env('OPENAI_AGENT_MODEL'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'timeout' => (int) env('OPENAI_TIMEOUT', 600),
    ],

];

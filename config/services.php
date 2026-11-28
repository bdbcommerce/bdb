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

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.8-flash'),
    ],

    // Invoice parser (Admin → Invoice parser): fallback when no key is saved in its settings.
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
    ],

    // The AI used while no model is switched on in Admin → AI settings: Claude (ANTHROPIC_API_KEY).
    // AI_DEFAULT_PROVIDER=gemini brings back the old GEMINI_API_KEY fallback (phpunit.xml sets it for the old tests).
    'ai' => [
        'default' => env('AI_DEFAULT_PROVIDER', 'anthropic'),
        'model' => env('AI_DEFAULT_MODEL', 'claude-opus-5-5'),
    ],

    // Voice messages are transcribed by NeuronAI (https://docs.neuronai.uz/uz/api/, key with the stt:write
    // permission, 1 credit per second of audio). While NEURONAI_API_KEY is empty the older Groq Whisper is used.
    'neuronai' => [
        'key' => env('NEURONAI_API_KEY'),
        'url' => rtrim((string) env('NEURONAI_BASE_URL', 'https://my.neuronai.uz/api/v1'), '/'),
        'language' => env('NEURONAI_LANGUAGE', 'auto'), // auto | uz | ru | en | kaa | kk …
    ],

    // Older transcriber, used only without a NeuronAI key: Groq Whisper. Without GROQ_API_KEY the key of
    // any Groq model in Admin → AI settings is used; with no Groq key voice messages are not transcribed.
    'groq' => [
        'key' => env('GROQ_API_KEY'),
        'transcribe_model' => env('GROQ_TRANSCRIBE_MODEL', 'whisper-large-v3'),
        'voice_daily_limit' => (int) env('TELEGRAM_VOICE_DAILY_LIMIT', 300),
        // Audio seconds a day (Groq free tier: about 28 800 a day / 7 200 an hour for Whisper).
        'voice_daily_seconds' => (int) env('TELEGRAM_VOICE_DAILY_SECONDS', 7200),
    ],

    // One bot does both jobs: customers chat with the AI assistant in private
    // messages (webhook), and new leads are posted to the sales chat_id.
    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        // The bot's @username for contact links when Profile & SEO leaves it empty.
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
        // false = record only: the bot saves every message but never replies, registers or calls the AI.
        // Sales assistant (Admin → Sales assistant) when its settings were never saved: on. Tests: off (phpunit.xml).
        'sales_assistant' => env('SALES_ASSISTANT_MODE', 'on') === 'off' ? 'off' : 'on',
        // Outside working hours when the admin has not chosen (Sales assistant → Settings): note | ai | silent.
        'sales_off_hours' => in_array(env('SALES_OFF_HOURS', 'ai'), ['note', 'ai', 'silent'], true) ? env('SALES_OFF_HOURS', 'ai') : 'ai',
        // Only assigned clients get the AI team; others the greeting once (Sales assistant → Settings). Tests: off.
        'sales_require_assignment' => filter_var(env('SALES_REQUIRE_ASSIGNMENT', true), FILTER_VALIDATE_BOOLEAN),
        'replies' => filter_var(env('TELEGRAM_BOT_REPLIES', false), FILTER_VALIDATE_BOOLEAN), // true/false, 1/0, on/off, yes/no
        // Voice / audio from customers: 'forward' (default) = sent to the sales chat, bot silent; 'transcribe' = Groq Whisper + AI answer.
        'voice' => env('TELEGRAM_VOICE_MODE', 'forward'),
        // AI answers a day for all unregistered (guest) customers together; admins are alerted when reached.
        'guest_daily_limit' => (int) env('TELEGRAM_GUEST_DAILY_LIMIT', 1000),
        // Technical alerts (AI assistant errors). Empty = the sales chat_id.
        'alert_chat_id' => env('TELEGRAM_ALERT_CHAT_ID'),
    ],

    // 1C HTTP service for company data by INN (Companies). Paths are added to the base URL; {inn} is replaced.
    'onec' => [
        'url' => env('ONEC_BASE_URL'),
        'login' => env('ONEC_LOGIN'),
        'password' => env('ONEC_PASSWORD'),
        // Company by INN: POST {url}/clients/newClient with {"stir": "<inn>"} (method GET: the INN goes in the path as {inn}).
        'company_path' => env('ONEC_COMPANY_PATH', '/clients/newClient'),
        'company_method' => strtoupper((string) env('ONEC_COMPANY_METHOD', 'POST')),
        'company_field' => env('ONEC_COMPANY_FIELD', 'stir'),
        'purchases_path' => env('ONEC_PURCHASES_PATH', '/companies/{inn}/purchases'), // empty = not used
        'items_path' => env('ONEC_ITEMS_PATH', '/crm/item'), // GET: the list of items
        'companies_path' => env('ONEC_COMPANIES_PATH', '/crm/company'), // GET: the list of companies
        'timeout' => (int) env('ONEC_TIMEOUT', 15),
    ],

    // Public company registry, asked when neither our base nor 1C knows the INN. {inn} is replaced in the URL.
    'registry' => [
        'url' => env('REGISTRY_URL'), // e.g. https://registry.example.uz/api/company/{inn}
        'token' => env('REGISTRY_TOKEN'), // sent as a Bearer token when set
        'timeout' => (int) env('REGISTRY_TIMEOUT', 15),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];

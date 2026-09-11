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

    /*
    |--------------------------------------------------------------------------
    | Voice AI Agent — OpenAI
    |--------------------------------------------------------------------------
    |
    | Powers speech-to-text (Whisper), the conversational brain (Chat
    | Completions) and text-to-speech for the voice agent.
    |
    */

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'chat_model' => env('OPENAI_CHAT_MODEL', 'gpt-4o-mini'),
        'stt_model' => env('OPENAI_STT_MODEL', 'whisper-1'),
        'tts_model' => env('OPENAI_TTS_MODEL', 'tts-1'),
        'tts_voice' => env('OPENAI_TTS_VOICE', 'alloy'),
    ],

    'groq' => [
        'key' => env('GROQ_API_KEY'),
        'chat_model' => env('GROQ_MODEL', env('GROQ_MODEL_FALLBACK', 'openai/gpt-oss-20b')),
        'stt_model' => env('GROQ_STT_MODEL', 'whisper-large-v3'),
    ],

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
    ],

    'openrouter' => [
        'key' => env('OPENROUTER_API_KEY'),
        'chat_model' => env('OPENROUTER_MODEL', 'openai/gpt-oss-20b:free'),
    ],

    'vpn' => [
        'executable_path' => env('VPN_EXECUTABLE_PATH'),
    ],

];

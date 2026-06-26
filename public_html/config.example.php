<?php
declare(strict_types=1);

// Copy this file to public_html/config.php for local-only settings.
// Do not commit public_html/config.php or paste it into chats, issues, or PRs.
// Environment variables with the same names take priority over these values.
// Leave GEMINI_API_KEY empty to use demo mode without calling the Gemini API.
return [
    // Get a real key from Google AI Studio: https://aistudio.google.com/apikey
    'GEMINI_API_KEY' => '',
    'GEMINI_MODEL' => 'gemini-3.1-flash-lite',

    // Use 'pgsql' for PostgreSQL or 'file' for simple JSON file storage.
    'STORAGE_DRIVER' => 'pgsql',
    'EVENT_STORAGE_PATH' => __DIR__ . '/data/events.json',

    'DB_HOST' => 'localhost',
    'DB_PORT' => '5432',
    'DB_USER' => '',
    'DB_PASS' => '',
    'DB_NAME' => '',

    // Days after plan adoption before the follow-up review banner appears.
    'FOLLOW_UP_DAYS' => '7',
    // Set to '1' to allow plan review before FOLLOW_UP_DAYS elapses (for testing).
    'ALLOW_EARLY_PLAN_REVIEW' => '0',
];

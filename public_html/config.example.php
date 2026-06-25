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

    'DB_HOST' => 'localhost',
    'DB_PORT' => '5432',
    'DB_USER' => '',
    'DB_PASS' => '',
    'DB_NAME' => '',
];

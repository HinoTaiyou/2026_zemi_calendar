<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Google AI Studio で取得: https://aistudio.google.com/apikey
define('GEMINI_API_KEY', 'your_api_key');
define('GEMINI_MODEL', 'gemini-2.0-flash');

define('DB_HOST', 'localhost');
define('DB_USER', 'taiyo0724');
define('DB_PASS', 'your_password');
define('DB_NAME', 'taiyo0724');

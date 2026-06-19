<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Google AI Studio で取得: https://aistudio.google.com/apikey
// 環境変数 GEMINI_API_KEY がある場合は、そちらが優先されます。
define('GEMINI_API_KEY', '');
define('GEMINI_MODEL', 'gemini-3.5-flash');

// 環境変数 DB_HOST / DB_PORT / DB_USER / DB_PASS / DB_NAME がある場合は、そちらが優先されます。
define('DB_HOST', 'localhost');
define('DB_PORT', '');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_password');
define('DB_NAME', 'your_db_name');

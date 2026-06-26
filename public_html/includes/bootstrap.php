<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/app_config.php';
loadAppConfigFile();

if (!defined('GEMINI_API_KEY')) {
    define('GEMINI_API_KEY', appConfigValue('GEMINI_API_KEY'));
}
if (!defined('GEMINI_MODEL')) {
    define('GEMINI_MODEL', appConfigValue('GEMINI_MODEL', 'gemini-3.1-flash-lite'));
}
if (!defined('DB_HOST')) {
    define('DB_HOST', appConfigValue('DB_HOST', 'localhost'));
}
if (!defined('DB_PORT')) {
    define('DB_PORT', appConfigValue('DB_PORT'));
}
if (!defined('DB_USER')) {
    define('DB_USER', appConfigValue('DB_USER'));
}
if (!defined('DB_PASS')) {
    define('DB_PASS', appConfigValue('DB_PASS', '', false));
}
if (!defined('DB_NAME')) {
    define('DB_NAME', appConfigValue('DB_NAME'));
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/events.php';

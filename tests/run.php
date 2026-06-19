<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../public_html/includes/validation.php';
require_once __DIR__ . '/../public_html/includes/security.php';
require_once __DIR__ . '/../public_html/includes/ai.php';
require_once __DIR__ . '/../public_html/includes/db.php';
require_once __DIR__ . '/../public_html/includes/chat_session.php';

$passed = 0;
$failed = 0;

function assertTest(string $name, bool $condition): void
{
    global $passed, $failed;

    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }

    $failed++;
    echo "FAIL {$name}\n";
}

function clearTestEnv(): void
{
    foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'GEMINI_API_KEY', 'GEMINI_MODEL'] as $name) {
        putenv($name . '=');
    }
}

function writeTempConfig(array $values): string
{
    $path = tempnam(sys_get_temp_dir(), 'calendar-config-');
    if ($path === false) {
        throw new RuntimeException('temporary config could not be created');
    }

    file_put_contents($path, "<?php\nreturn " . var_export($values, true) . ";\n");
    return $path;
}

clearTestEnv();
$missingConfig = sys_get_temp_dir() . '/calendar-config-missing-' . bin2hex(random_bytes(4)) . '.php';
setAppConfigPathForTest($missingConfig);
assertTest('config.php missing is detected', !appConfigFileExists());
assertTest('DB_PASS undefined is missing', in_array('DB_PASS', getMissingDbConfigKeys(getDbConfig()), true));
assertTest('Gemini key missing enables demo mode', isGeminiDemoMode());

$configPath = writeTempConfig([
    'GEMINI_API_KEY' => 'config-test-key',
    'GEMINI_MODEL' => 'gemini-3.5-flash',
    'DB_HOST' => 'localhost',
    'DB_USER' => 'config-user',
    'DB_PASS' => 'config-pass',
    'DB_NAME' => 'config-db',
]);
setAppConfigPathForTest($configPath);
assertTest('config.php present is detected', appConfigFileExists());
assertTest('Gemini config fallback is read', getGeminiApiKey() === 'config-test-key');
assertTest('Gemini key configured disables demo mode', !isGeminiDemoMode());
assertTest('DB config fallback is read', getDbConfig()['password'] === 'config-pass');

putenv('GEMINI_API_KEY=env-test-key');
putenv('DB_PASS=env-db-pass');
assertTest('Gemini env key has priority', getGeminiApiKey() === 'env-test-key');
assertTest('DB env password has priority', getDbConfig()['password'] === 'env-db-pass');

putenv('GEMINI_API_KEY=');
putenv('DB_PASS=');
assertTest('empty env key falls back to config', getGeminiApiKey() === 'config-test-key');
assertTest('empty env DB_PASS falls back to config', getDbConfig()['password'] === 'config-pass');

$emptyPassConfig = writeTempConfig([
    'DB_HOST' => 'localhost',
    'DB_USER' => 'config-user',
    'DB_PASS' => '',
    'DB_NAME' => 'config-db',
]);
setAppConfigPathForTest($emptyPassConfig);
assertTest('DB_PASS empty string is missing', in_array('DB_PASS', getMissingDbConfigKeys(getDbConfig()), true));

$zeroPassConfig = writeTempConfig([
    'DB_HOST' => 'localhost',
    'DB_USER' => 'config-user',
    'DB_PASS' => '0',
    'DB_NAME' => 'config-db',
]);
setAppConfigPathForTest($zeroPassConfig);
assertTest('DB_PASS zero string is configured', !in_array('DB_PASS', getMissingDbConfigKeys(getDbConfig()), true));

$cwd = getcwd();
chdir('/');
assertTest('config path is not cwd dependent', getDbConfig()['password'] === '0');
if (is_string($cwd)) {
    chdir($cwd);
}

clearTestEnv();
setAppConfigPathForTest($missingConfig);
try {
    getDb();
    assertTest('DB missing config throws config error', false);
} catch (DatabaseException $e) {
    assertTest('DB missing config throws config error', $e->getDbErrorCode() === 'DB_CONFIG_MISSING');
    assertTest('DB config error message hides secrets and paths', !str_contains($e->getMessage(), 'config-pass') && !str_contains($e->getMessage(), 'public_html/config.php'));
}

function geminiErrorBody(int $code, string $status): string
{
    return json_encode([
        'error' => [
            'code' => $code,
            'status' => $status,
            'message' => 'safe test error',
        ],
    ], JSON_UNESCAPED_UNICODE) ?: '{}';
}

function geminiTextBody(string $text): string
{
    return json_encode([
        'candidates' => [
            [
                'content' => [
                    'parts' => [
                        ['text' => $text],
                    ],
                ],
            ],
        ],
    ], JSON_UNESCAPED_UNICODE) ?: '{}';
}

assertTest('Gemini 429 classified as rate limited', classifyGeminiHttpError(429, geminiErrorBody(429, 'RESOURCE_EXHAUSTED')) === 'GEMINI_RATE_LIMITED');
assertTest('Gemini 401 classified as auth failed', classifyGeminiHttpError(401, geminiErrorBody(401, 'UNAUTHENTICATED')) === 'GEMINI_AUTH_FAILED');
assertTest('Gemini 403 classified as auth failed', classifyGeminiHttpError(403, geminiErrorBody(403, 'PERMISSION_DENIED')) === 'GEMINI_AUTH_FAILED');
assertTest('Gemini 404 classified as model not found', classifyGeminiHttpError(404, geminiErrorBody(404, 'NOT_FOUND')) === 'GEMINI_MODEL_NOT_FOUND');
assertTest('Gemini 500 classified as server error', classifyGeminiHttpError(500, geminiErrorBody(500, 'INTERNAL')) === 'GEMINI_SERVER_ERROR');
assertTest('Gemini 503 classified as server error', classifyGeminiHttpError(503, geminiErrorBody(503, 'UNAVAILABLE')) === 'GEMINI_SERVER_ERROR');

putenv('GEMINI_API_KEY=fake-test-key');
$callCount = 0;
setGeminiHttpClientForTest(static function () use (&$callCount): array {
    $callCount++;
    return [
        'http_code' => 429,
        'body' => geminiErrorBody(429, 'RESOURCE_EXHAUSTED'),
        'curl_errno' => 0,
    ];
});
$rateLimitedEvents = null;
try {
    $rateLimitedEvents = generateScheduleWithGemini('2026-06-19', '2026-06-20', 1, '英語');
    assertTest('Gemini 429 throws instead of returning events', false);
} catch (GeminiApiException $e) {
    assertTest('Gemini 429 throws rate limit error', $e->getGeminiErrorCode() === 'GEMINI_RATE_LIMITED');
    assertTest('Gemini 429 returns no event data', $rateLimitedEvents === null);
    assertTest('Gemini 429 does not call JSON repair', $callCount === 1);
    assertTest('Gemini 429 message does not include API key', !str_contains($e->getMessage(), 'fake-test-key'));
}

$callCount = 0;
setGeminiHttpClientForTest(static function () use (&$callCount): array {
    $callCount++;
    return [
        'http_code' => 429,
        'body' => geminiErrorBody(429, 'RESOURCE_EXHAUSTED'),
        'curl_errno' => 0,
    ];
});
try {
    chatWithScheduleAssistant([
        ['role' => 'user', 'content' => '基本情報を勉強したい'],
        ['role' => 'user', 'content' => '平日夜でお願いします'],
    ]);
    assertTest('Gemini 429 does not silently switch to demo', false);
} catch (GeminiApiException $e) {
    assertTest('Gemini 429 does not silently switch to demo', $e->getGeminiErrorCode() === 'GEMINI_RATE_LIMITED' && $callCount === 1);
}

$validScheduleJson = '{"events":[{"date":"2026-06-19","time":"10:00","duration_minutes":30,"title":"英語"}]}';
$callCount = 0;
setGeminiHttpClientForTest(static function () use (&$callCount, $validScheduleJson): array {
    $callCount++;
    return [
        'http_code' => 200,
        'body' => geminiTextBody($validScheduleJson),
        'curl_errno' => 0,
    ];
});
$generated = generateScheduleWithGemini('2026-06-19', '2026-06-20', 1, '英語');
assertTest('Gemini 200 normal JSON returns events', count($generated) === 1 && $callCount === 1);

$callCount = 0;
setGeminiHttpClientForTest(static function () use (&$callCount, $validScheduleJson): array {
    $callCount++;
    return [
        'http_code' => 200,
        'body' => geminiTextBody($callCount === 1 ? 'not-json' : $validScheduleJson),
        'curl_errno' => 0,
    ];
});
$repaired = generateScheduleWithGemini('2026-06-19', '2026-06-20', 1, '英語');
assertTest('Gemini invalid JSON repairs once', count($repaired) === 1 && $callCount === 2);
setGeminiHttpClientForTest(null);
putenv('GEMINI_API_KEY=');

assertTest('date 2026-02-28 valid', isStrictDateYmd('2026-02-28'));
assertTest('date 2026-02-29 invalid', !isStrictDateYmd('2026-02-29'));
assertTest('date 2026-02-31 invalid', !isStrictDateYmd('2026-02-31'));
assertTest('date 2024-02-29 valid', isStrictDateYmd('2024-02-29'));
assertTest('date empty invalid', !isStrictDateYmd(''));
assertTest('date bad format invalid', !isStrictDateYmd('2026/02/28'));

assertTest('time 00:00 valid', isStrictTimeHm('00:00'));
assertTest('time 23:59 valid', isStrictTimeHm('23:59'));
assertTest('time 24:00 invalid', !isStrictTimeHm('24:00'));
assertTest('time 12:60 invalid', !isStrictTimeHm('12:60'));
assertTest('time 99:99 invalid', !isStrictTimeHm('99:99'));
assertTest('time 9:00 invalid', !isStrictTimeHm('9:00'));

$sameTime = validateEventData('2026-06-19', '10:00', 0, 'test');
assertTest('same start/end invalid', !$sameTime['valid']);
$afterEnd = validateEventData('2026-06-19', '23:30', 60, 'test');
assertTest('end after day invalid', !$afterEnd['valid']);

assertTest('overlap 10-11 vs 10:30-11:30', timeRangesOverlap(600, 660, 630, 690));
assertTest('overlap 10-11 vs 09-10:01', timeRangesOverlap(600, 660, 540, 601));
assertTest('touching 10-11 vs 11-12 not overlap', !timeRangesOverlap(600, 660, 660, 720));
assertTest('touching 10-11 vs 09-10 not overlap', !timeRangesOverlap(600, 660, 540, 600));

$_SESSION = [];
$token = csrfToken();
$_POST = ['csrf_token' => $token];
assertTest('csrf valid token', isValidCsrfPost());
$_POST = [];
assertTest('csrf missing rejected', !isValidCsrfPost());
$_POST = ['csrf_token' => 'bad-' . $token];
assertTest('csrf tampered rejected', !isValidCsrfPost());

$json = '{"events":[{"date":"2026-06-19","time":"10:00","duration_minutes":30,"title":"英語"}]}';
assertTest('AI normal JSON accepted', count(parseEventsFromAiResponse($json)) === 1);
$fenced = "```json\n{$json}\n```";
assertTest('AI fenced JSON accepted', count(parseEventsFromAiResponse($fenced)) === 1);

try {
    parseEventsFromAiResponse('{"events":[{"date":"2026-02-31","time":"10:00","duration_minutes":30,"title":"英語"}]}');
    assertTest('AI invalid date rejected', false);
} catch (Throwable $e) {
    assertTest('AI invalid date rejected', true);
}

try {
    parseEventsFromAiResponse('{"events":[{"date":"2026-06-19","time":"99:99","duration_minutes":30,"title":"英語"}]}');
    assertTest('AI invalid time rejected', false);
} catch (Throwable $e) {
    assertTest('AI invalid time rejected', true);
}

$tokenized = ensureChatEventTokens([
    ['date' => '2026-06-19', 'time' => '10:00', 'duration_minutes' => 30, 'title' => '英語'],
]);
assertTest('AI proposal token added', isset($tokenized[0]['ai_idempotency_key']));
$retokenized = ensureChatEventTokens($tokenized);
assertTest('AI proposal token stable', $tokenized[0]['ai_idempotency_key'] === $retokenized[0]['ai_idempotency_key']);

echo "\nPassed: {$passed}, Failed: {$failed}\n";
exit($failed === 0 ? 0 : 1);

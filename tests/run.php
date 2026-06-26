<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../public_html/includes/validation.php';
require_once __DIR__ . '/../public_html/includes/security.php';
require_once __DIR__ . '/../public_html/includes/ai.php';
require_once __DIR__ . '/../public_html/includes/db.php';
require_once __DIR__ . '/../public_html/includes/chat_session.php';
require_once __DIR__ . '/../public_html/includes/event_admin.php';

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

assertTest('scroll intent empty on GET',
    determineChatScrollTarget('', '', false, false, false) === '');
assertTest('scroll intent empty on reset GET-equivalent',
    determineChatScrollTarget('', 'ignored', true, true, true) === '');
assertTest('scroll intent feedback on POST error',
    determineChatScrollTarget('message', 'some error', false, false, false) === 'feedback');
assertTest('scroll intent plans on new plans generated',
    determineChatScrollTarget('message', '', true, false, true) === 'plans');
assertTest('scroll intent events on new events',
    determineChatScrollTarget('message', '', false, true, true) === 'events');
assertTest('scroll intent latest-reply on plain reply',
    determineChatScrollTarget('message', '', false, false, true) === 'latest-reply');
assertTest('scroll intent empty when nothing happened',
    determineChatScrollTarget('message', '', false, false, false) === '');
assertTest('scroll intent events on select_plan',
    determineChatScrollTarget('select_plan', '', false, true, false) === 'events');
assertTest('scroll intent latest-reply not plans for reply only (past plans in session)',
    determineChatScrollTarget('message', '', false, false, true) === 'latest-reply');
assertTest('scroll intent feedback wins over plans generated',
    determineChatScrollTarget('message', 'AI error', true, false, true) === 'feedback');
assertTest('scroll intent plans wins over latest-reply when both',
    determineChatScrollTarget('message', '', true, false, true) === 'plans');

// ---------------------------------------------------------------------------
// Qualification study planner
// ---------------------------------------------------------------------------
setAppNowForTest(new DateTimeImmutable('2026-06-25 10:00', new DateTimeZone('Asia/Tokyo')));
$spNow = appNow();
$spAvail = [
    ['weekday' => 1, 'start' => '19:00', 'end' => '22:00'],
    ['weekday' => 3, 'start' => '19:00', 'end' => '22:00'],
    ['weekday' => 6, 'start' => '10:00', 'end' => '17:00'],
];
$spGoal = static function (array $patch) use ($spNow): array {
    return mergeStudyGoal(studyGoalDefaults(), validateStudyGoalPatch($patch, $spNow));
};

// Current date / timezone
assertTest('study now uses injected date', $spNow->format('Y-m-d') === '2026-06-25');
assertTest('study now uses Asia/Tokyo', $spNow->getTimezone()->getName() === 'Asia/Tokyo');

// Date validation / past-date rejection
$pastPatch = validateStudyGoalPatch(['start_date' => '2025-01-01', 'target_date' => '2025-06-01'], $spNow);
assertTest('past start_date rejected', !isset($pastPatch['start_date']));
assertTest('past target_date rejected', !isset($pastPatch['target_date']));
$badOrder = validateStudyGoalPatch(['start_date' => '2026-07-01', 'target_date' => '2026-06-30'], $spNow);
assertTest('target before start dropped', isset($badOrder['start_date']) && !isset($badOrder['target_date']));
$futureDates = validateStudyGoalPatch(['start_date' => '2026-07-01', 'target_date' => '2026-10-01'], $spNow);
assertTest('future dates accepted', ($futureDates['start_date'] ?? '') === '2026-07-01' && ($futureDates['target_date'] ?? '') === '2026-10-01');

// Window resolution
$winToday = resolveStudyWindow($spGoal(['duration_months' => 1]), $spNow);
assertTest('today-from start defaults to today', ($winToday['start'] ?? '') === '2026-06-25');
$win3mo = resolveStudyWindow($spGoal(['duration_months' => 3]), $spNow);
assertTest('3 months is a calendar month span', ($win3mo['end'] ?? '') === '2026-09-25');
$winYear = resolveStudyWindow($spGoal(['duration_months' => 8]), $spNow);
assertTest('window crosses the year boundary', ($winYear['end'] ?? '') === '2027-02-25');
assertTest('window capped at 18 months', (resolveStudyWindow($spGoal(['duration_months' => 18]), $spNow)['end'] ?? '') === '2027-12-25');

// Minutes accounting
assertTest('15h converts to 900 minutes', studyTotalMinutes($spGoal(['selected_total_hours' => 15])) === 900);
assertTest('weekly available minutes summed', weeklyAvailableMinutes($spAvail) === 780);

// Full-range expansion — THE regression for "only the first week"
$feasibleGoal = $spGoal([
    'qualification_name' => '基本情報', 'goal_type' => 'pass_fail',
    'selected_total_hours' => 100, 'duration_months' => 3,
    'desired_weekly_hours' => 10, 'availability' => $spAvail,
]);
$feasiblePlans = buildStudyPlanOptions($feasibleGoal, $spNow);
assertTest('study builds three plans', count($feasiblePlans) === 3);
$planB = $feasiblePlans[1] ?? ['events' => [], 'stats' => []];
$bEvents = $planB['events'];
$bDates = array_column($bEvents, 'date');
sort($bDates);
$bMonths = array_unique(array_map(static fn(string $d): string => substr($d, 0, 7), $bDates));
assertTest('expansion exceeds the first week', count($bEvents) > 3);
assertTest('expansion spans multiple months', count($bMonths) >= 3);
assertTest('expansion reaches the final month', ($bDates[count($bDates) - 1] ?? '') >= '2026-08-01');
assertTest('no occurrence before the start date', ($bDates[0] ?? '') >= ($planB['stats']['start_date'] ?? '9999'));
assertTest('no occurrence after the end date', ($bDates[count($bDates) - 1] ?? '') <= ($planB['stats']['end_date'] ?? '0000'));
assertTest('feasible plan hits the exact total', ($planB['stats']['total_minutes'] ?? 0) === 6000);
assertTest('feasible plan is marked feasible', ($planB['stats']['feasible'] ?? false) === true);
assertTest('only available weekdays are used', array_reduce($bEvents, static function (bool $ok, array $e): bool {
    $iso = (int) DateTimeImmutable::createFromFormat('!Y-m-d', $e['date'])->format('N');
    return $ok && in_array($iso, [1, 3, 6], true);
}, true));

// Plan cards share the same fixed fields, always incl. weekday/time
$labelsA = array_column(planCardFields($feasiblePlans[0]), 'label');
$labelsC = array_column(planCardFields($feasiblePlans[2]), 'label');
assertTest('A/B/C cards share fixed field order', $labelsA === $labelsC && $labelsA !== []);
assertTest('plan card always carries weekday/time', ($feasiblePlans[0]['stats']['weekday_time_summary'] ?? '') !== '');

// Shortfall vs feasible
$shortGoal = $spGoal([
    'qualification_name' => '統計検定2級', 'selected_total_hours' => 180,
    'duration_months' => 3, 'desired_weekly_hours' => 15, 'availability' => $spAvail,
]);
$shortPlan = buildStudyPlanOptions($shortGoal, $spNow)[1] ?? ['stats' => []];
assertTest('infeasible plan reports shortfall', ($shortPlan['stats']['feasible'] ?? true) === false && ($shortPlan['stats']['shortfall_minutes'] ?? 0) > 0);

// Passed-today slot is skipped (now = 2026-06-25 10:00; Thu=weekday 4)
$todayGoal = $spGoal([
    'qualification_name' => 'x', 'selected_total_hours' => 20, 'duration_months' => 1,
    'availability' => [
        ['weekday' => 4, 'start' => '09:00', 'end' => '12:00'],
        ['weekday' => 4, 'start' => '14:00', 'end' => '17:00'],
    ],
]);
$todayEvents = buildStudyPlanOptions($todayGoal, $spNow)[0]['events'] ?? [];
$firstOcc = $todayEvents[0] ?? ['date' => '', 'time' => ''];
assertTest('passed slot today is skipped', !($firstOcc['date'] === '2026-06-25' && $firstOcc['time'] === '09:00'));
assertTest('future slot today is kept', $firstOcc['date'] === '2026-06-25' && $firstOcc['time'] === '14:00');

// Insufficient info → no selectable plans
assertTest('no availability yields no plans', buildStudyPlanOptions($spGoal(['qualification_name' => 'x', 'selected_total_hours' => 50, 'duration_months' => 3]), $spNow) === []);
assertTest('no plans until ready', !isStudyGoalReadyForPlans($spGoal(['qualification_name' => 'x']), $spNow));
assertTest('missing fields listed when bare', in_array('学習できる曜日・時間帯', missingStudyFields($spGoal(['qualification_name' => 'x'])), true));

// Event cap
$capTemplate = [];
for ($wd = 1; $wd <= 7; $wd++) {
    $capTemplate[] = ['weekday' => $wd, 'start' => '19:00', 'duration_minutes' => 15];
}
$capped = expandTemplateAcrossRange($capTemplate, '2026-06-25', '2028-06-25', 1000000, $spNow, '学習');
assertTest('expansion respects the 500-event cap', count($capped) === STUDY_MAX_EVENTS);

// Deterministic idempotency key
$keyEvent = ['date' => '2026-07-01', 'time' => '19:00', 'duration_minutes' => 90, 'title' => '統計 学習'];
$k1 = studyOccurrenceIdempotencyKey('A', '統計', $keyEvent);
$k2 = studyOccurrenceIdempotencyKey('A', '統計', $keyEvent);
assertTest('idempotency key is deterministic', $k1 === $k2);
assertTest('idempotency key is valid format', normalizeAiIdempotencyKey($k1) === $k1);
assertTest('idempotency key varies by plan', studyOccurrenceIdempotencyKey('B', '統計', $keyEvent) !== $k1);

// Structured JSON parsing (no live Gemini)
$okJson = '{"reply":"こんにちは","action":"ask_missing_information","goal_patch":{"qualification_name":"統計検定2級","unknown_field":"x"}}';
$parsedOk = parseStudyGoalResponse($okJson);
assertTest('study JSON reply parsed', $parsedOk['reply'] === 'こんにちは');
assertTest('study JSON action parsed', $parsedOk['action'] === 'ask_missing_information');
assertTest('study JSON goal_patch parsed', ($parsedOk['goal_patch']['qualification_name'] ?? '') === '統計検定2級');
assertTest('unknown patch field ignored on validate', !array_key_exists('unknown_field', validateStudyGoalPatch($parsedOk['goal_patch'], $spNow)));
$fenced = "```json\n{$okJson}\n```";
assertTest('study JSON fenced block parsed', parseStudyGoalResponse($fenced)['action'] === 'ask_missing_information');
$noJson = parseStudyGoalResponse('ただのテキストです');
assertTest('study no-JSON falls back to chat', $noJson['action'] === 'chat' && $noJson['goal_patch'] === []);
$threwBrokenJson = false;
try {
    parseStudyGoalResponse('{"reply": "x", "action":');
} catch (Throwable $e) {
    $threwBrokenJson = true;
}
assertTest('study broken JSON throws for repair', $threwBrokenJson);
assertTest('empty qualification name dropped', !array_key_exists('qualification_name', validateStudyGoalPatch(['qualification_name' => '  '], $spNow)));
assertTest('score goal target parsed', (validateStudyGoalPatch(['goal_type' => 'score', 'target_score' => 650], $spNow)['target_score'] ?? 0) === 650);

// End-to-end through the AI path with a fake client (no live Gemini)
setStudyGoalState(studyGoalDefaults());
putenv('GEMINI_API_KEY=fake-study-key');
$studyResponse = json_encode([
    'reply' => 'プランを用意しました',
    'action' => 'ready_for_plan',
    'goal_patch' => [
        'qualification_name' => '統計検定2級',
        'goal_type' => 'pass_fail',
        'selected_total_hours' => 100,
        'duration_months' => 3,
        'desired_weekly_hours' => 10,
        'start_date' => '2025-01-01', // past date from AI must be ignored
        'availability' => [
            ['weekday' => 1, 'start' => '19:00', 'end' => '22:00'],
            ['weekday' => 3, 'start' => '19:00', 'end' => '22:00'],
            ['weekday' => 6, 'start' => '10:00', 'end' => '17:00'],
        ],
    ],
], JSON_UNESCAPED_UNICODE);
setGeminiHttpClientForTest(static fn(): array => [
    'http_code' => 200,
    'body' => geminiTextBody($studyResponse),
    'curl_errno' => 0,
]);
$turn = chatWithScheduleAssistant([['role' => 'user', 'content' => '統計検定2級を3か月で']]);
assertTest('AI turn returns reply', $turn['reply'] === 'プランを用意しました');
assertTest('AI turn builds three plans', count($turn['plans']) === 3);
$allFuture = true;
foreach ($turn['plans'][0]['events'] as $e) {
    if ($e['date'] < '2026-06-25') {
        $allFuture = false;
        break;
    }
}
assertTest('AI past start_date is ignored (no past events)', $allFuture);
assertTest('AI turn merges goal into session', getStudyGoalState()['qualification_name'] === '統計検定2級');
setGeminiHttpClientForTest(null);
putenv('GEMINI_API_KEY=');
setStudyGoalState(studyGoalDefaults());
setAppNowForTest(null);

// ---------------------------------------------------------------------------
// Bulk event management
// ---------------------------------------------------------------------------
setAppNowForTest(new DateTimeImmutable('2026-06-26 10:00', new DateTimeZone('Asia/Tokyo')));
$beNow = appNow();

// Filter normalization
$nf = normalizeBulkFilter([
    'start_date' => '2026-07-01', 'end_date' => '2026-09-30',
    'weekdays' => ['1', '3', '3', '9', '0'], 'keyword' => '  統計  ',
    'source' => 'study_plan', 'future_only' => '1',
], $beNow);
assertTest('bulk filter keeps valid date range', $nf['start_date'] === '2026-07-01' && $nf['end_date'] === '2026-09-30');
assertTest('bulk filter dedupes/sorts/validates weekdays', $nf['weekdays'] === [1, 3]);
assertTest('bulk filter trims keyword', $nf['keyword'] === '統計');
assertTest('bulk filter keeps valid source', $nf['source'] === 'study_plan');
assertTest('bulk filter parses future_only', $nf['future_only'] === true);
assertTest('bulk filter drops invalid date', normalizeBulkFilter(['start_date' => '2026-13-40'], $beNow)['start_date'] === null);
assertTest('bulk filter drops invalid source to any', normalizeBulkFilter(['source' => 'bogus'], $beNow)['source'] === 'any');
assertTest('bulk filter caps keyword length', mb_strlen(normalizeBulkFilter(['keyword' => str_repeat('あ', 250)], $beNow)['keyword']) === BULK_KEYWORD_MAX_LENGTH);

// Empty-filter rejection
assertTest('empty bulk filter detected', isBulkFilterEmpty(bulkFilterDefaults()));
assertTest('non-empty bulk filter detected', !isBulkFilterEmpty($nf));
assertTest('future_only alone is not empty', !isBulkFilterEmpty(normalizeBulkFilter(['future_only' => '1'], $beNow)));

// WHERE building
[$wAll, $pAll] = buildBulkFilterWhere($nf, $beNow);
assertTest('where has placeholders for dates', str_contains($wAll, 'event_date >= $1') && str_contains($wAll, 'event_date <= $2'));
assertTest('where future_only adds today param', in_array('2026-06-26', $pAll, true));
assertTest('where weekdays use integer literals', str_contains($wAll, 'EXTRACT(ISODOW FROM event_date)::int IN (1,3)'));
assertTest('where keyword uses ILIKE ESCAPE', str_contains($wAll, "title ILIKE") && str_contains($wAll, "ESCAPE '\\'"));
assertTest('where source equality param present', in_array('study_plan', $pAll, true));
[$wUnknown] = buildBulkFilterWhere(normalizeBulkFilter(['source' => 'unknown'], $beNow), $beNow);
assertTest('unknown source maps to IS NULL', str_contains($wUnknown, 'source_type IS NULL'));
[$wEmpty] = buildBulkFilterWhere(bulkFilterDefaults(), $beNow);
assertTest('empty filter where matches nothing', $wEmpty === '1=0');
[, $pKw] = buildBulkFilterWhere(normalizeBulkFilter(['keyword' => '10%_off'], $beNow), $beNow);
assertTest('keyword wildcards are escaped', $pKw[0] === '%10\\%\\_off%');
assertTest('escapeLikeTerm escapes backslash', escapeLikeTerm('a\\b%c_d') === 'a\\\\b\\%c\\_d');

// Fingerprint
assertTest('fingerprint stable', bulkFilterFingerprint($nf) === bulkFilterFingerprint($nf));
$nf2 = $nf;
$nf2['keyword'] = 'TOEIC';
assertTest('fingerprint changes on edit', bulkFilterFingerprint($nf) !== bulkFilterFingerprint($nf2));

// Strong confirm
assertTest('strong confirm when >= threshold', bulkFilterRequiresStrongConfirm($nf, 50));
assertTest('no strong confirm for bounded small range', !bulkFilterRequiresStrongConfirm($nf, 5));
assertTest('strong confirm for unbounded keyword-only', bulkFilterRequiresStrongConfirm(normalizeBulkFilter(['keyword' => '統計'], $beNow), 5));
assertTest('no strong confirm for specific batch small', !bulkFilterRequiresStrongConfirm(normalizeBulkFilter(['batch_id' => 'spb_abc'], $beNow), 5));
assertTest('bulk delete cap is 500', BULK_DELETE_MAX === 500);

// Summary text
$summary = bulkFilterSummaryText($nf);
assertTest('summary includes range', str_contains($summary, '2026-07-01〜2026-09-30'));
assertTest('summary includes weekday names', str_contains($summary, '月・水'));
assertTest('summary includes keyword', str_contains($summary, '「統計」'));
assertTest('empty summary says none', bulkFilterSummaryText(bulkFilterDefaults()) === '条件なし');
assertTest('source label maps study_plan', bulkSourceLabel('study_plan') === 'AI学習予定');

// CSV escaping / injection guard
assertTest('csv neutralizes formula', csvCell('=SUM(A1)') === "'=SUM(A1)");
assertTest('csv neutralizes plus/at/minus', csvCell('+x') === "'+x" && csvCell('@y') === "'@y" && csvCell('-z') === "'-z");
assertTest('csv quotes commas and quotes', csvCell('a,"b"') === '"a,""b"""');
assertTest('csv quotes newlines', csvCell("line1\nline2") === "\"line1\nline2\"");
assertTest('csv leaves plain text', csvCell('統計検定2級 学習') === '統計検定2級 学習');

// Source metadata validation + batch ids
$srcEvent = validateEventPayload([
    'date' => '2026-07-01', 'time' => '19:00', 'duration_minutes' => 90, 'title' => 'x',
    'source_type' => 'study_plan', 'source_batch_id' => 'spb_abc-123', 'source_label' => 'ラベル',
]);
assertTest('valid source_type kept', $srcEvent['source_type'] === 'study_plan');
assertTest('valid source_batch_id kept', $srcEvent['source_batch_id'] === 'spb_abc-123');
assertTest('source_label kept', $srcEvent['source_label'] === 'ラベル');
$badSrc = validateEventPayload([
    'date' => '2026-07-01', 'time' => '19:00', 'duration_minutes' => 90, 'title' => 'x',
    'source_type' => 'hacker', 'source_batch_id' => 'bad id!', 'source_label' => '',
]);
assertTest('invalid source_type dropped', $badSrc['source_type'] === null);
assertTest('invalid batch id dropped', $badSrc['source_batch_id'] === null);
assertTest('empty source_label is null', $badSrc['source_label'] === null);
assertTest('study batch id deterministic', studyBatchId('統計検定2級', 'B') === studyBatchId('統計検定2級', 'B'));
assertTest('study batch id varies by plan', studyBatchId('統計検定2級', 'A') !== studyBatchId('統計検定2級', 'B'));
assertTest('study batch id valid format', normalizeSourceBatchId(studyBatchId('統計検定2級', 'B')) === studyBatchId('統計検定2級', 'B'));
assertTest('study batch label combines qual and plan', studyBatchLabel('統計検定2級', 'バランス') === '統計検定2級 ・ バランス');

setAppNowForTest(null);

echo "\nPassed: {$passed}, Failed: {$failed}\n";
exit($failed === 0 ? 0 : 1);

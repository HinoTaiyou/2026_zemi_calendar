<?php
declare(strict_types=1);

require_once __DIR__ . '/app_config.php';
require_once __DIR__ . '/plan_constraints.php';
require_once __DIR__ . '/validation.php';

class GeminiApiException extends RuntimeException
{
    private string $geminiErrorCode;
    private int $httpStatus;

    public function __construct(string $geminiErrorCode, string $message, int $httpStatus = 0)
    {
        parent::__construct($message);
        $this->geminiErrorCode = $geminiErrorCode;
        $this->httpStatus = $httpStatus;
    }

    public function getGeminiErrorCode(): string
    {
        return $this->geminiErrorCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}

function getGeminiApiKey(): string
{
    return appConfigValue('GEMINI_API_KEY');
}

function getGeminiModel(): string
{
    return appConfigValue('GEMINI_MODEL', 'gemini-3.5-flash');
}

function isGeminiDemoMode(): bool
{
    return getGeminiApiKey() === '';
}

function geminiUserMessage(string $errorCode): string
{
    return match ($errorCode) {
        'API_KEY_MISSING' => 'AIサービスの設定がありません。管理者に確認してください。',
        'GEMINI_RATE_LIMITED' => 'AIサービスの利用上限に達しているため、現在プランを生成できません。しばらくしてから再度お試しください。',
        'GEMINI_AUTH_FAILED' => 'AIサービスの設定を確認できませんでした。管理者に確認してください。',
        'GEMINI_MODEL_NOT_FOUND' => '設定されているAIモデルを利用できません。',
        'GEMINI_SERVER_ERROR' => 'AIサービスで一時的な問題が発生しています。',
        'GEMINI_TIMEOUT' => 'AIサービスとの通信がタイムアウトしました。しばらくしてから再度お試しください。',
        default => 'AIサービスの応答を処理できませんでした。',
    };
}

function classifyGeminiHttpError(int $httpCode, string $responseBody): string
{
    $data = json_decode($responseBody, true);
    $status = is_array($data) ? (string) ($data['error']['status'] ?? '') : '';

    if ($httpCode === 429 || $status === 'RESOURCE_EXHAUSTED') {
        return 'GEMINI_RATE_LIMITED';
    }
    if (in_array($httpCode, [401, 403], true) || in_array($status, ['UNAUTHENTICATED', 'PERMISSION_DENIED'], true)) {
        return 'GEMINI_AUTH_FAILED';
    }
    if ($httpCode === 404 || $status === 'NOT_FOUND') {
        return 'GEMINI_MODEL_NOT_FOUND';
    }
    if ($httpCode >= 500) {
        return 'GEMINI_SERVER_ERROR';
    }

    return 'GEMINI_INVALID_RESPONSE';
}

function sendGeminiHttpRequest(string $url, array $headers, string $payload): array
{
    $testClient = $GLOBALS['gemini_http_client_for_test'] ?? null;
    if (is_callable($testClient)) {
        return $testClient($url, $headers, $payload);
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return [
            'http_code' => 0,
            'body' => '',
            'curl_errno' => -1,
        ];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 60,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrno = curl_errno($ch);
    curl_close($ch);

    return [
        'http_code' => $httpCode,
        'body' => $response === false ? '' : (string) $response,
        'curl_errno' => $curlErrno,
    ];
}

function setGeminiHttpClientForTest(?callable $client): void
{
    if ($client === null) {
        unset($GLOBALS['gemini_http_client_for_test']);
        return;
    }

    $GLOBALS['gemini_http_client_for_test'] = $client;
}

function callGemini(string $systemPrompt, array $messages, float $temperature = 0.6): string
{
    $apiKey = getGeminiApiKey();
    if ($apiKey === '') {
        throw new GeminiApiException('API_KEY_MISSING', geminiUserMessage('API_KEY_MISSING'));
    }

    $contents = [];
    foreach ($messages as $message) {
        $role = $message['role'] ?? '';
        $content = trim((string) ($message['content'] ?? ''));

        if ($content === '' || !in_array($role, ['user', 'assistant'], true)) {
            continue;
        }

        $contents[] = [
            'role' => $role === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $content]],
        ];
    }

    if ($contents !== [] && $contents[0]['role'] === 'model') {
        array_shift($contents);
    }

    if ($contents === []) {
        throw new RuntimeException('AIに送るメッセージがありません。');
    }

    $payload = json_encode([
        'system_instruction' => [
            'parts' => [['text' => $systemPrompt]],
        ],
        'contents' => $contents,
        'generationConfig' => [
            'temperature' => $temperature,
        ],
    ], JSON_UNESCAPED_UNICODE);

    if ($payload === false) {
        throw new RuntimeException('リクエストの作成に失敗しました。');
    }

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
        . urlencode(getGeminiModel())
        . ':generateContent';

    $result = sendGeminiHttpRequest(
        $url,
        [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $apiKey,
        ],
        $payload
    );

    $httpCode = (int) ($result['http_code'] ?? 0);
    $response = (string) ($result['body'] ?? '');
    $curlErrno = (int) ($result['curl_errno'] ?? 0);

    if ($curlErrno !== 0) {
        $errorCode = $curlErrno === 28 ? 'GEMINI_TIMEOUT' : 'GEMINI_SERVER_ERROR';
        throw new GeminiApiException($errorCode, geminiUserMessage($errorCode), $httpCode);
    }

    $data = json_decode($response, true);
    if ($httpCode !== 200) {
        $errorCode = classifyGeminiHttpError($httpCode, $response);
        throw new GeminiApiException($errorCode, geminiUserMessage($errorCode), $httpCode);
    }

    if (!is_array($data)) {
        throw new GeminiApiException('GEMINI_INVALID_RESPONSE', geminiUserMessage('GEMINI_INVALID_RESPONSE'), $httpCode);
    }

    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if ($text === '') {
        throw new GeminiApiException('GEMINI_INVALID_RESPONSE', geminiUserMessage('GEMINI_INVALID_RESPONSE'), $httpCode);
    }

    return $text;
}

function extractJsonCandidate(string $content): ?string
{
    if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/i', $content, $matches)) {
        $content = trim($matches[1]);
    }

    $start = strpos($content, '{');
    if ($start === false) {
        return null;
    }

    $depth = 0;
    $inString = false;
    $escaped = false;
    $length = strlen($content);

    for ($i = $start; $i < $length; $i++) {
        $char = $content[$i];

        if ($inString) {
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($char === '\\') {
                $escaped = true;
                continue;
            }
            if ($char === '"') {
                $inString = false;
            }
            continue;
        }

        if ($char === '"') {
            $inString = true;
            continue;
        }
        if ($char === '{') {
            $depth++;
        } elseif ($char === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($content, $start, $i - $start + 1);
            }
        }
    }

    throw new RuntimeException('AIのJSON応答が途中で途切れています。');
}

function decodeJsonObject(string $json): array
{
    try {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException('AIのJSON応答を解析できませんでした。');
    }

    if (!is_array($data)) {
        throw new RuntimeException('AIのJSON応答がオブジェクトではありません。');
    }

    return $data;
}

function buildJsonRepairPrompt(string $rawContent): string
{
    return <<<PROMPT
次のAI応答を、予定作成アプリが読める有効なJSONだけに修正してください。
説明文、Markdown、コードフェンスは含めないでください。

必須形式:
{"events":[{"date":"YYYY-MM-DD","time":"HH:MM","duration_minutes":30,"title":"タイトル"}]}

または:
{"constraints":{"required_hours":120,"exam_date":"2026-12-01","min_hours_per_week":5},"plans":[{"id":"A","name":"プラン名","summary":"概要","events":[{"date":"YYYY-MM-DD","time":"HH:MM","duration_minutes":30,"title":"タイトル"}]}]}

元の応答:
{$rawContent}
PROMPT;
}

function generateSchedule(
    string $startDate,
    string $endDate,
    int $timesPerWeek,
    string $activities
): array {
    if (getGeminiApiKey() !== '') {
        return generateScheduleWithGemini($startDate, $endDate, $timesPerWeek, $activities);
    }

    return generateScheduleDemo($startDate, $endDate, $timesPerWeek, $activities);
}

function generateScheduleWithGemini(
    string $startDate,
    string $endDate,
    int $timesPerWeek,
    string $activities
): array {
    $prompt = <<<PROMPT
以下の条件に従って、具体的な予定表をJSON形式のみで返してください。

期間: {$startDate} から {$endDate}
頻度: 週{$timesPerWeek}回
やりたいこと:
{$activities}

ルール:
- 期間内の日付のみ使用
- 1日の予定は09:00〜21:00の間
- 同じ日に複数予定を入れてよい
- 週{$timesPerWeek}回のペースを守る
- 日本語のタイトルを付ける

出力形式（JSONのみ、説明文不要）:
{"events":[{"date":"YYYY-MM-DD","time":"HH:MM","duration_minutes":30,"title":"タイトル"}]}
PROMPT;

    $content = callGemini(
        'You output valid JSON only.',
        [['role' => 'user', 'content' => $prompt]],
        0.4
    );

    try {
        return parseEventsFromAiResponse($content);
    } catch (Throwable $e) {
        $repaired = callGemini(
            'You repair invalid schedule JSON. Output valid JSON only.',
            [['role' => 'user', 'content' => buildJsonRepairPrompt($content)]],
            0.1
        );

        return parseEventsFromAiResponse($repaired);
    }
}

function generateScheduleDemo(
    string $startDate,
    string $endDate,
    int $timesPerWeek,
    string $activities
): array {
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $activities) ?: [])));

    if ($lines === []) {
        $lines = ['学習（30分）'];
    }

    $events = [];
    $lineIndex = 0;
    $slotHours = [9, 12, 15, 18, 19, 20];
    $weekCounter = 0;

    for ($current = clone $start; $current <= $end; $current->modify('+1 day')) {
        if ((int) $current->format('w') === 0) {
            $weekCounter = 0;
        }

        if ($weekCounter >= $timesPerWeek) {
            continue;
        }

        if (in_array((int) $current->format('w'), [0, 6], true) && $timesPerWeek <= 5) {
            continue;
        }

        $activity = $lines[$lineIndex % count($lines)];
        $lineIndex++;
        $weekCounter++;

        $duration = 30;
        if (preg_match('/(\d+)\s*分/u', $activity, $matches)) {
            $duration = max(15, (int) $matches[1]);
        }

        $hour = $slotHours[($lineIndex - 1) % count($slotHours)];

        $events[] = [
            'date' => $current->format('Y-m-d'),
            'time' => sprintf('%02d:00', $hour),
            'duration_minutes' => $duration,
            'title' => preg_replace('/（\d+分）|\(\d+分\)/u', '', $activity) ?: $activity,
        ];
    }

    return $events;
}

function getChatSystemPrompt(array $constraints = []): string
{
    $constraintBlock = '';
    $minHours = $constraints['min_hours_per_week'] ?? null;
    $requiredHours = $constraints['required_hours'] ?? null;
    $examDate = $constraints['exam_date'] ?? null;

    if ($minHours !== null) {
        $constraintBlock = "\n\n【硬い条件（必ず守る）】\n";
        if ($requiredHours !== null) {
            $constraintBlock .= "- 必要学習時間: 約{$requiredHours}時間\n";
        }
        if ($examDate !== null) {
            $constraintBlock .= "- 試験・目標日: {$examDate}\n";
        }
        $constraintBlock .= "- 各プランは週{$minHours}時間以上の学習時間を確保すること\n";
        $constraintBlock .= "- この時間は削ってはいけない。資格・試験対策の最低ライン\n";
    }

    return <<<PROMPT
あなたは予定作成の相談相手です。日本語で親しみやすく会話してください。

ルール:
- 最初はユーザーの希望（やりたいこと、期間、頻度）をヒアリングする
- 資格・試験の場合は必要時間と試験日を確認する
- 情報が足りなければ、時間帯・曜日などを質問する
- 十分な情報が集まったら、**3つのプラン（A/B/C）** を提示する
  - プランA: 平日集中型
  - プランB: 分散型（毎日コツコツ）
  - プランC: 週末中心型
- ユーザーが修正を求めたら、選ばれたプランを更新して再提示する
- カレンダーへの登録はユーザーがボタンで行うので、「登録しました」とは言わない
- 予定は09:00〜21:00の間
- プラン提示時は本文で各プランの特徴を説明し、最後にJSONブロックを1つ付ける
{$constraintBlock}

JSON形式（プラン提示時のみ）:
```json
{"constraints":{"required_hours":120,"exam_date":"2026-12-01","min_hours_per_week":5},"plans":[{"id":"A","name":"平日集中型","summary":"月水金 19:00","events":[{"date":"YYYY-MM-DD","time":"HH:MM","duration_minutes":30,"title":"タイトル"}]},{"id":"B","name":"分散型","summary":"毎日30分","events":[]},{"id":"C","name":"週末中心型","summary":"土日各2時間","events":[]}]}
```

- まだ相談中で案がないときはJSONブロックを付けない
- 修正時は1〜3プランを返してよい
PROMPT;
}

function chatWithScheduleAssistant(array $messages): array
{
    $constraints = extractConstraintsFromMessages($messages);

    if (getGeminiApiKey() !== '') {
        $content = callGemini(getChatSystemPrompt($constraints), $messages);
        try {
            return parseChatAssistantResponse($content, $constraints);
        } catch (Throwable $e) {
            $repaired = callGemini(
                'You repair invalid schedule planning JSON. Output valid JSON only.',
                [['role' => 'user', 'content' => buildJsonRepairPrompt($content)]],
                0.1
            );

            return parseChatAssistantResponse($repaired, $constraints);
        }
    }

    return chatDemoResponse($messages, $constraints);
}

function chatDemoResponse(array $messages, array $constraints = []): array
{
    $userMessages = array_values(array_filter(
        $messages,
        static fn(array $message): bool => ($message['role'] ?? '') === 'user'
    ));
    $userCount = count($userMessages);

    if ($userCount <= 1) {
        $extra = ($constraints['required_hours'] ?? null) !== null
            ? '必要時間は把握しました。何時頃が勉強しやすいですか？'
            : '何時頃が理想ですか？平日と休日、どちらがよいですか？';

        return [
            'reply' => 'いいですね！もう少し教えてください。' . $extra,
            'events' => [],
            'plans' => [],
            'constraints' => $constraints,
        ];
    }

    $combinedText = implode("\n", array_map(
        static fn(array $message): string => (string) ($message['content'] ?? ''),
        $userMessages
    ));

    $plans = buildDemoPlans($combinedText, $constraints);
    $constraintNote = buildConstraintsSummary($constraints);
    $reply = 'ありがとうございます。3つのプランを用意しました。'
        . '近いものを選ぶか、修正したい点を教えてください。';

    if ($constraintNote !== '') {
        $reply = '【条件】' . $constraintNote . "\n\n" . $reply;
    }

    return [
        'reply' => $reply,
        'events' => [],
        'plans' => $plans,
        'constraints' => $constraints,
    ];
}

function buildDemoPlans(string $text, array $constraints): array
{
    $startDate = date('Y-m-d');
    $endDate = date('Y-m-d', strtotime('+1 month'));

    if (preg_match('/(\d{4}-\d{2}-\d{2}).*?(\d{4}-\d{2}-\d{2})/u', $text, $matches)) {
        $startDate = $matches[1];
        $endDate = $matches[2];
    } elseif (preg_match('/(\d{4}-\d{2}-\d{2})/u', $text, $matches)) {
        $startDate = $matches[1];
        $endDate = date('Y-m-d', strtotime($startDate . ' +1 month'));
    }

    if (($constraints['exam_date'] ?? null) !== null) {
        $endDate = min($endDate, $constraints['exam_date']);
    }

    $activity = '学習';
    if (preg_match('/(基本情報|応用情報|英語|筋トレ|資格)/u', $text, $matches)) {
        $activity = $matches[1];
    }

    $minHours = $constraints['min_hours_per_week'] ?? null;
    $durationA = $minHours !== null && $minHours >= 5 ? 90 : 60;
    $durationB = 30;
    $durationC = $minHours !== null && $minHours >= 5 ? 120 : 90;

    $eventsA = generateScheduleDemo($startDate, $endDate, 3, $activity . "（{$durationA}分）");
    $eventsB = generateScheduleDemo($startDate, $endDate, 5, $activity . "（{$durationB}分）");
    $eventsC = generateScheduleDemo($startDate, $endDate, 2, $activity . "（{$durationC}分）");

    return [
        [
            'id' => 'A',
            'name' => '平日集中型',
            'summary' => '月水金 19:00〜',
            'events' => $eventsA,
        ],
        [
            'id' => 'B',
            'name' => '分散型',
            'summary' => '平日中心・毎日コツコツ',
            'events' => $eventsB,
        ],
        [
            'id' => 'C',
            'name' => '週末中心型',
            'summary' => '土日にまとめて学習',
            'events' => $eventsC,
        ],
    ];
}

function parseChatAssistantResponse(string $content, array $fallbackConstraints = []): array
{
    $events = [];
    $plans = [];
    $constraints = $fallbackConstraints;
    $reply = $content;

    $json = null;
    $fullJsonBlock = null;
    if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/i', $content, $matches)) {
        $json = trim($matches[1]);
        $fullJsonBlock = $matches[0];
    } elseif (str_contains($content, '{')) {
        $json = extractJsonCandidate($content);
        $fullJsonBlock = $json;
    }

    if ($json !== null) {
        $reply = trim(str_replace((string) $fullJsonBlock, '', $content));
        $parsed = parsePlansPayload($json, $fallbackConstraints);
        $events = $parsed['events'];
        $plans = $parsed['plans'];
        $constraints = $parsed['constraints'];
    }

    $reply = trim($reply);

    if ($reply === '') {
        if ($plans !== []) {
            $reply = '3つのプランを作成しました。気に入ったものを選ぶか、修正点を教えてください。';
        } elseif ($events !== []) {
            $reply = '予定案を作成しました。内容を確認してください。';
        } else {
            $reply = 'もう少し詳しく教えてください。';
        }
    }

    return [
        'reply' => $reply,
        'events' => $events,
        'plans' => $plans,
        'constraints' => $constraints,
    ];
}

function parsePlansPayload(string $json, array $fallbackConstraints = []): array
{
    $data = decodeJsonObject($json);

    $constraints = $fallbackConstraints;
    if (isset($data['constraints']) && is_array($data['constraints'])) {
        $constraints = mergeConstraints($constraints, [
            'required_hours' => $data['constraints']['required_hours'] ?? null,
            'exam_date' => $data['constraints']['exam_date'] ?? null,
            'label' => $data['constraints']['label'] ?? null,
        ]);
        if (isset($data['constraints']['min_hours_per_week'])) {
            $constraints['min_hours_per_week'] = (float) $data['constraints']['min_hours_per_week'];
        } else {
            $constraints['min_hours_per_week'] = calculateMinHoursPerWeek(
                is_int($constraints['required_hours'] ?? null) ? $constraints['required_hours'] : null,
                is_string($constraints['exam_date'] ?? null) ? $constraints['exam_date'] : null
            );
        }
    }

    $plans = [];
    if (isset($data['plans']) && is_array($data['plans'])) {
        foreach ($data['plans'] as $plan) {
            if (!is_array($plan)) {
                continue;
            }

            $planEvents = parseEventsList($plan['events'] ?? []);
            if ($planEvents === []) {
                continue;
            }

            $plans[] = [
                'id' => (string) ($plan['id'] ?? ''),
                'name' => (string) ($plan['name'] ?? 'プラン'),
                'summary' => (string) ($plan['summary'] ?? ''),
                'events' => $planEvents,
            ];
        }
    }

    if ($plans === [] && isset($data['events']) && is_array($data['events'])) {
        $events = parseEventsList($data['events']);
        return ['events' => $events, 'plans' => [], 'constraints' => $constraints];
    }

    $events = $plans[0]['events'] ?? [];

    return ['events' => $events, 'plans' => $plans, 'constraints' => $constraints];
}

function parseEventsList(array $rawEvents): array
{
    $events = [];
    foreach ($rawEvents as $event) {
        if (!is_array($event)) {
            throw new RuntimeException('AIの予定形式が正しくありません。');
        }

        $events[] = validateEventPayload([
            'date' => $event['date'] ?? null,
            'time' => $event['time'] ?? null,
            'duration_minutes' => $event['duration_minutes'] ?? null,
            'title' => $event['title'] ?? null,
        ]);
    }

    usort($events, static function (array $a, array $b): int {
        return [$a['date'], $a['time']] <=> [$b['date'], $b['time']];
    });

    return $events;
}

function parseEventsPayload(string $json): array
{
    $data = decodeJsonObject($json);
    if (!isset($data['events']) || !is_array($data['events'])) {
        return [];
    }

    return parseEventsList($data['events']);
}

function parseEventsFromAiResponse(string $content): array
{
    $json = extractJsonCandidate($content);
    if ($json === null) {
        throw new RuntimeException('AIの応答にJSONが含まれていません。');
    }

    $data = decodeJsonObject($json);
    if (!isset($data['events']) || !is_array($data['events'])) {
        throw new RuntimeException('AIの応答を解析できませんでした。');
    }

    $events = parseEventsPayload(json_encode($data, JSON_UNESCAPED_UNICODE) ?: '{}');

    if ($events === []) {
        throw new RuntimeException('有効な予定が生成されませんでした。');
    }

    return $events;
}

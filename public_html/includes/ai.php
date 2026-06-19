<?php
declare(strict_types=1);

require_once __DIR__ . '/plan_constraints.php';

function getGeminiApiKey(): string
{
    return defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
}

function getGeminiModel(): string
{
    if (defined('GEMINI_MODEL') && GEMINI_MODEL !== '') {
        return GEMINI_MODEL;
    }

    return 'gemini-2.0-flash';
}

function callGemini(string $systemPrompt, array $messages, float $temperature = 0.6): string
{
    $apiKey = getGeminiApiKey();
    if ($apiKey === '') {
        throw new RuntimeException('GEMINI_API_KEY が未設定です。');
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
        . ':generateContent?key=' . urlencode($apiKey);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 60,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('API通信に失敗しました: ' . $curlError);
    }

    $data = json_decode($response, true);
    if ($httpCode !== 200) {
        $message = $data['error']['message'] ?? 'Gemini APIエラーが発生しました。';
        throw new RuntimeException($message);
    }

    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if ($text === '') {
        throw new RuntimeException('AIからの応答が空でした。');
    }

    return $text;
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

    return parseEventsFromAiResponse($content);
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
        return parseChatAssistantResponse($content, $constraints);
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

    if (preg_match('/```json\s*(\{.*?\})\s*```/s', $content, $matches)) {
        $reply = trim(str_replace($matches[0], '', $content));
        $parsed = parsePlansPayload($matches[1], $fallbackConstraints);
        $events = $parsed['events'];
        $plans = $parsed['plans'];
        $constraints = $parsed['constraints'];
    } elseif (preg_match('/(\{[\s\S]*"plans"[\s\S]*\})/', $content, $matches)) {
        $reply = trim(str_replace($matches[0], '', $content));
        $parsed = parsePlansPayload($matches[1], $fallbackConstraints);
        $events = $parsed['events'];
        $plans = $parsed['plans'];
        $constraints = $parsed['constraints'];
    } elseif (preg_match('/(\{"events":\[.*?\]\})/s', $content, $matches)) {
        $reply = trim(str_replace($matches[0], '', $content));
        $events = parseEventsPayload($matches[1]);
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
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return ['events' => [], 'plans' => [], 'constraints' => $fallbackConstraints];
    }

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
            continue;
        }

        $date = $event['date'] ?? '';
        $title = trim((string) ($event['title'] ?? ''));
        $time = $event['time'] ?? '09:00';
        $duration = (int) ($event['duration_minutes'] ?? 30);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $title === '') {
            continue;
        }

        $events[] = [
            'date' => $date,
            'time' => $time,
            'duration_minutes' => max(15, $duration),
            'title' => $title,
        ];
    }

    usort($events, static function (array $a, array $b): int {
        return [$a['date'], $a['time']] <=> [$b['date'], $b['time']];
    });

    return $events;
}

function parseEventsPayload(string $json): array
{
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['events']) || !is_array($data['events'])) {
        return [];
    }

    return parseEventsList($data['events']);
}

function parseEventsFromAiResponse(string $content): array
{
    if (preg_match('/\{.*\}/s', $content, $matches)) {
        $content = $matches[0];
    }

    $data = json_decode($content, true);
    if (!is_array($data) || !isset($data['events']) || !is_array($data['events'])) {
        throw new RuntimeException('AIの応答を解析できませんでした。');
    }

    $events = parseEventsPayload(json_encode($data, JSON_UNESCAPED_UNICODE) ?: '{}');

    if ($events === []) {
        throw new RuntimeException('有効な予定が生成されませんでした。');
    }

    return $events;
}

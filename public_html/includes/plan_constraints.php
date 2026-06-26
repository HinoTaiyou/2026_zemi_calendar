<?php
declare(strict_types=1);

function normalizeWideDigits(string $text): string
{
    return strtr($text, [
        '０' => '0',
        '１' => '1',
        '２' => '2',
        '３' => '3',
        '４' => '4',
        '５' => '5',
        '６' => '6',
        '７' => '7',
        '８' => '8',
        '９' => '9',
    ]);
}

function extractConstraintsFromText(string $text): array
{
    $text = normalizeWideDigits($text);
    $constraints = [
        'required_hours' => null,
        'exam_date' => null,
        'label' => null,
        'time_preference' => null,
        'preferred_hours' => null,
        'daily_hours' => null,
    ];

    $constraints['required_hours'] = extractRequiredHoursFromText($text);
    $constraints['daily_hours'] = extractDailyHoursFromText($text);

    if (preg_match('/(\d{4})[年\/\-](\d{1,2})[月\/\-](\d{1,2})/u', $text, $matches)) {
        $constraints['exam_date'] = sprintf('%04d-%02d-%02d', (int) $matches[1], (int) $matches[2], (int) $matches[3]);
    } elseif (preg_match('/(\d{4}-\d{2}-\d{2})/u', $text, $matches)) {
        $constraints['exam_date'] = $matches[1];
    }

    if (preg_match('/(基本情報|応用情報|資格|試験|検定)/u', $text, $matches)) {
        $constraints['label'] = $matches[1];
    } elseif (preg_match('/英検.{0,6}準?\s*[１1]\s*級/u', $text)) {
        $constraints['label'] = '英検準1級';
    } elseif (preg_match('/英検.{0,6}準?\s*[２2]\s*級/u', $text)) {
        $constraints['label'] = '英検準2級';
    } elseif (preg_match('/英検/u', $text)) {
        $constraints['label'] = '英検';
    } elseif (preg_match('/トーイック|TOEIC/ui', $text)) {
        $constraints['label'] = 'TOEIC';
    } elseif (preg_match('/筋トレ/u', $text)) {
        $constraints['label'] = '筋トレ';
    } elseif (preg_match('/英語/u', $text)) {
        $constraints['label'] = '英語';
    }

    $constraints['time_preference'] = extractTimePreferenceFromText($text);
    $constraints['preferred_hours'] = extractPreferredHoursFromText($text);

    return $constraints;
}

function extractRequiredHoursFromText(string $text): ?int
{
    $text = normalizeWideDigits($text);
    if (preg_match('/(?:必要(?:な)?学習時間|必要時間|総学習時間|学習時間は|要する時間)[^\d]{0,16}(\d+)\s*時間/u', $text, $matches)) {
        return (int) $matches[1];
    }

    if (preg_match('/(\d+)\s*時間(?:\s*(?:必要|くらい|程度|は必要|勉強|学習))?/u', $text, $matches, PREG_OFFSET_CAPTURE)) {
        $byteOffset = $matches[0][1];
        $before = mb_substr(substr($text, 0, $byteOffset), -4, null, 'UTF-8');
        if (preg_match('/1日|毎日|平均/u', $before)) {
            return null;
        }

        return (int) $matches[1][0];
    }

    return null;
}

function extractDailyHoursFromText(string $text): ?float
{
    $text = normalizeWideDigits($text);
    if (preg_match('/1日\s*(\d+(?:\.\d+)?)\s*時間/u', $text, $matches)) {
        return (float) $matches[1];
    }

    if (preg_match('/毎日\s*(\d+(?:\.\d+)?)\s*時間/u', $text, $matches)) {
        return (float) $matches[1];
    }

    if (preg_match('/平均\s*(\d+(?:\.\d+)?)\s*時間/u', $text, $matches)) {
        return (float) $matches[1];
    }

    if (preg_match('/(\d+(?:\.\d+)?)\s*時間\s*(?:くらい|程度|は|が)?\s*(?:できる|可能|やれる|勉強|確保)/u', $text, $matches)) {
        return (float) $matches[1];
    }

    if (preg_match('/(\d+(?:\.\d+)?)\s*時間\s*\/\s*日/u', $text, $matches)) {
        return (float) $matches[1];
    }

    if (preg_match('/1日\s*(\d+)\s*時間半/u', $text, $matches)) {
        return (float) $matches[1] + 0.5;
    }

    return null;
}

function extractTimePreferenceFromText(string $text): ?string
{
    if (preg_match('/(夜中心|夜型|ナイト|夜間|平日夜|夜に|夜は|夜が|夜で|夜希望|夜メイン)/u', $text)) {
        return 'evening';
    }

    if (preg_match('/(夕方|夕刻|夕方以降|夕方中心|夕方が|夕方で|晩|夕)/u', $text)) {
        return 'evening';
    }

    if (preg_match('/(朝中心|朝型|早朝|午前中|朝に|朝は|朝が|朝で|朝希望)/u', $text)) {
        return 'morning';
    }

    if (preg_match('/(昼中心|午後中心|午後に|午後は|午後が|午後で|昼に|昼は)/u', $text)) {
        return 'afternoon';
    }

    if (preg_match('/夜/u', $text)) {
        return 'evening';
    }

    return null;
}

function extractPreferredHoursFromText(string $text): ?array
{
    if (!preg_match_all('/(\d{1,2})\s*[:：時]/u', $text, $matches)) {
        return null;
    }

    $hours = [];
    foreach ($matches[1] as $hourText) {
        $hour = (int) $hourText;
        if ($hour >= 9 && $hour <= 21) {
            $hours[$hour] = $hour;
        }
    }

    if ($hours === []) {
        return null;
    }

    sort($hours);

    return array_values($hours);
}

function getPreferredSlotHours(?string $timePreference, ?array $preferredHours = null): array
{
    if ($preferredHours !== null && $preferredHours !== []) {
        return $preferredHours;
    }

    return match ($timePreference) {
        'morning' => [9, 10, 11],
        'afternoon' => [13, 14, 15, 16],
        'evening' => [18, 19, 20, 21],
        default => [9, 12, 15, 18, 19, 20],
    };
}

function formatTimePreferenceLabel(?string $timePreference): string
{
    return match ($timePreference) {
        'morning' => '朝中心',
        'afternoon' => '昼・午後中心',
        'evening' => '夜・夕方中心',
        default => '',
    };
}

function mergeConstraints(array $base, array $incoming): array
{
    foreach (['required_hours', 'exam_date', 'label', 'time_preference', 'daily_hours'] as $key) {
        if (($incoming[$key] ?? null) !== null && $incoming[$key] !== '') {
            $base[$key] = $incoming[$key];
        }
    }

    if (($incoming['preferred_hours'] ?? null) !== null && $incoming['preferred_hours'] !== []) {
        $base['preferred_hours'] = $incoming['preferred_hours'];
    }

    return $base;
}

function isQualificationTopic(array $constraints, string $text): bool
{
    $label = (string) ($constraints['label'] ?? '');
    if (in_array($label, ['基本情報', '応用情報', '資格', '試験', '検定', '英検', '英検準1級', '英検準2級', 'TOEIC', '英語'], true)) {
        return true;
    }

    return preg_match('/(資格|試験|検定|英検|トーイック|TOEIC|基本情報|応用情報|英語)/ui', $text) === 1;
}

function isFitnessTopic(array $constraints, string $text): bool
{
    $label = (string) ($constraints['label'] ?? '');

    return $label === '筋トレ' || preg_match('/筋トレ/u', $text) === 1;
}

function hasStatedPlanningGoal(string $text): bool
{
    return preg_match(
        '/(取りたい|したい|学びたい|受けたい|続けたい|始めたい|目指|'
        . '英検|英語|筋トレ|資格|試験|検定|基本情報|応用情報|トーイック|TOEIC|'
        . '勉強|学習|トレーニング)/ui',
        $text
    ) === 1;
}

function resolveQualificationStudyEstimate(string $text, array $constraints = []): ?array
{
    $patterns = [
        ['pattern' => '/英検.{0,6}準?\s*[１1]\s*級/u', 'label' => '英検準1級', 'hours' => 350],
        ['pattern' => '/英検.{0,6}準?\s*[２2]\s*級/u', 'label' => '英検準2級', 'hours' => 200],
        ['pattern' => '/英検\s*1\s*級/u', 'label' => '英検1級', 'hours' => 500],
        ['pattern' => '/英検\s*2\s*級/u', 'label' => '英検2級', 'hours' => 150],
        ['pattern' => '/英検/u', 'label' => '英検', 'hours' => 150],
        ['pattern' => '/基本情報/u', 'label' => '基本情報技術者', 'hours' => 100],
        ['pattern' => '/応用情報/u', 'label' => '応用情報技術者', 'hours' => 200],
        ['pattern' => '/トーイック|TOEIC/ui', 'label' => 'TOEIC', 'hours' => 120],
    ];

    foreach ($patterns as $item) {
        if (preg_match($item['pattern'], $text)) {
            return [
                'label' => $item['label'],
                'hours' => $item['hours'],
            ];
        }
    }

    $label = (string) ($constraints['label'] ?? '');
    $fallbacks = [
        '英検' => ['label' => '英検', 'hours' => 150],
        'TOEIC' => ['label' => 'TOEIC', 'hours' => 120],
        '基本情報' => ['label' => '基本情報技術者', 'hours' => 100],
        '応用情報' => ['label' => '応用情報技術者', 'hours' => 200],
    ];

    if (isset($fallbacks[$label])) {
        return $fallbacks[$label];
    }

    return null;
}

function enrichConstraintsWithEstimates(array $constraints, string $text): array
{
    if (($constraints['required_hours'] ?? null) === null) {
        $estimate = resolveQualificationStudyEstimate($text, $constraints);
        if ($estimate !== null) {
            $constraints['required_hours'] = $estimate['hours'];
        }
    }

    return $constraints;
}

function buildDailyCapacityQuestion(array $constraints, string $text): string
{
    if (isFitnessTopic($constraints, $text)) {
        return '1日あたり、どのくらい筋トレに時間を使えそうですか？';
    }

    if (isQualificationTopic($constraints, $text)) {
        $estimate = resolveQualificationStudyEstimate($text, $constraints);
        if ($estimate !== null) {
            return $estimate['label']
                . 'の平均的な学習時間は約' . $estimate['hours'] . '時間と言われています。'
                . '1日あたり、何時間くらい勉強できそうですか？';
        }

        return '1日あたり、何時間くらい勉強できそうですか？';
    }

    return '1日あたり、どのくらいの時間を使えそうですか？';
}

function buildTimePreferenceQuestion(array $constraints, string $text): string
{
    if (isFitnessTopic($constraints, $text)) {
        return '筋トレしやすい時間帯はありますか？（例：平日朝、夜、週末など）';
    }

    return '勉強しやすい時間帯はありますか？（例：平日夜、朝、週末など）';
}

function finalizePlanningConstraints(array $constraints): array
{
    $requiredHours = $constraints['required_hours'] ?? null;
    $examDate = $constraints['exam_date'] ?? null;
    $fromExam = calculateMinHoursPerWeek(
        is_int($requiredHours) ? $requiredHours : null,
        is_string($examDate) ? $examDate : null
    );

    $dailyHours = $constraints['daily_hours'] ?? null;
    $fromDaily = null;
    if (is_int($dailyHours) || is_float($dailyHours)) {
        $fromDaily = round((float) $dailyHours * 5, 1);
    }

    if ($fromExam !== null && $fromDaily !== null) {
        $constraints['min_hours_per_week'] = max($fromExam, $fromDaily);
    } elseif ($fromExam !== null) {
        $constraints['min_hours_per_week'] = $fromExam;
    } elseif ($fromDaily !== null) {
        $constraints['min_hours_per_week'] = $fromDaily;
    }

    return $constraints;
}

function buildPlanningFollowUpQuestion(array $constraints, string $text): ?string
{
    $constraints = finalizePlanningConstraints($constraints);

    if (!hasStatedPlanningGoal($text)) {
        return 'どんなことを予定に入れたいですか？（例：英検勉強、筋トレなど）';
    }

    if (($constraints['daily_hours'] ?? null) === null) {
        return buildDailyCapacityQuestion($constraints, $text);
    }

    if (isQualificationTopic($constraints, $text)) {
        $estimate = resolveQualificationStudyEstimate($text, $constraints);
        if (($constraints['required_hours'] ?? null) === null && $estimate === null) {
            return '合格までに、だいたい何時間くらい学習が必要だと思いますか？（目安で大丈夫です）';
        }

        if (($constraints['exam_date'] ?? null) === null) {
            return '試験日や目標の期限はいつごろですか？';
        }
    }

    if (($constraints['time_preference'] ?? null) === null) {
        return buildTimePreferenceQuestion($constraints, $text);
    }

    return null;
}

function isPlanningReady(array $constraints, string $text): bool
{
    return buildPlanningFollowUpQuestion($constraints, $text) === null;
}

function calculateMinHoursPerWeek(?int $requiredHours, ?string $examDate): ?float
{
    if ($requiredHours === null || $requiredHours <= 0 || $examDate === null) {
        return null;
    }

    $exam = DateTime::createFromFormat('Y-m-d', $examDate);
    if ($exam === false) {
        return null;
    }

    $today = new DateTime('today');
    if ($exam <= $today) {
        return (float) $requiredHours;
    }

    $days = (int) $today->diff($exam)->days;
    if ($days <= 0) {
        return (float) $requiredHours;
    }

    $weeks = max(1, $days / 7);

    return round($requiredHours / $weeks, 1);
}

function calculatePlanWeeklyHours(array $events): float
{
    if ($events === []) {
        return 0.0;
    }

    $dates = array_column($events, 'date');
    sort($dates);
    $firstDate = $dates[0];
    $weekStart = DateTime::createFromFormat('Y-m-d', $firstDate);
    if ($weekStart === false) {
        return 0.0;
    }

    $weekEnd = clone $weekStart;
    $weekEnd->modify('+6 days');
    $weekEndStr = $weekEnd->format('Y-m-d');

    $minutes = 0;
    foreach ($events as $event) {
        $date = $event['date'] ?? '';
        if ($date >= $firstDate && $date <= $weekEndStr) {
            $minutes += (int) ($event['duration_minutes'] ?? 0);
        }
    }

    return round($minutes / 60, 1);
}

function planMeetsConstraint(array $events, ?float $minHoursPerWeek): bool
{
    if ($minHoursPerWeek === null) {
        return true;
    }

    return calculatePlanWeeklyHours($events) >= $minHoursPerWeek;
}

function buildConstraintsSummary(array $constraints): string
{
    $constraints = finalizePlanningConstraints($constraints);
    $requiredHours = $constraints['required_hours'] ?? null;
    $examDate = $constraints['exam_date'] ?? null;
    $dailyHours = $constraints['daily_hours'] ?? null;
    $minHours = $constraints['min_hours_per_week'] ?? null;

    $parts = [];
    if (($constraints['label'] ?? '') !== '') {
        $parts[] = (string) $constraints['label'];
    }
    $timeLabel = formatTimePreferenceLabel($constraints['time_preference'] ?? null);
    if ($timeLabel !== '') {
        $parts[] = $timeLabel;
    }
    if ($requiredHours !== null) {
        $parts[] = '必要学習時間: 約' . $requiredHours . '時間';
    }
    if ($examDate !== null) {
        $parts[] = '目標日: ' . $examDate;
    }
    if ($dailyHours !== null) {
        $parts[] = '希望: 1日' . $dailyHours . '時間';
    }
    if ($minHours !== null) {
        $parts[] = '推奨: 週' . $minHours . '時間以上';
    }

    return implode(' / ', $parts);
}

function extractConstraintsFromMessages(array $messages): array
{
    $constraints = [
        'required_hours' => null,
        'exam_date' => null,
        'label' => null,
        'time_preference' => null,
        'preferred_hours' => null,
        'daily_hours' => null,
        'min_hours_per_week' => null,
    ];

    foreach ($messages as $message) {
        if (($message['role'] ?? '') !== 'user') {
            continue;
        }

        $constraints = mergeConstraints(
            $constraints,
            extractConstraintsFromText((string) ($message['content'] ?? ''))
        );
    }

    $combinedText = implode("\n", array_map(
        static fn(array $message): string => (string) ($message['content'] ?? ''),
        array_values(array_filter(
            $messages,
            static fn(array $message): bool => ($message['role'] ?? '') === 'user'
        ))
    ));

    return finalizePlanningConstraints(enrichConstraintsWithEstimates($constraints, $combinedText));
}

<?php
declare(strict_types=1);

function extractConstraintsFromText(string $text): array
{
    $constraints = [
        'required_hours' => null,
        'exam_date' => null,
        'label' => null,
    ];

    if (preg_match('/(\d+)\s*時間/u', $text, $matches)) {
        $constraints['required_hours'] = (int) $matches[1];
    }

    if (preg_match('/(\d{4})[年\/\-](\d{1,2})[月\/\-](\d{1,2})/u', $text, $matches)) {
        $constraints['exam_date'] = sprintf('%04d-%02d-%02d', (int) $matches[1], (int) $matches[2], (int) $matches[3]);
    } elseif (preg_match('/(\d{4}-\d{2}-\d{2})/u', $text, $matches)) {
        $constraints['exam_date'] = $matches[1];
    }

    if (preg_match('/(基本情報|応用情報|資格|試験|検定)/u', $text, $matches)) {
        $constraints['label'] = $matches[1];
    }

    return $constraints;
}

function mergeConstraints(array $base, array $incoming): array
{
    foreach (['required_hours', 'exam_date', 'label'] as $key) {
        if (($incoming[$key] ?? null) !== null && $incoming[$key] !== '') {
            $base[$key] = $incoming[$key];
        }
    }

    return $base;
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
    $requiredHours = $constraints['required_hours'] ?? null;
    $examDate = $constraints['exam_date'] ?? null;
    $minHours = $constraints['min_hours_per_week'] ?? calculateMinHoursPerWeek(
        is_int($requiredHours) ? $requiredHours : null,
        is_string($examDate) ? $examDate : null
    );

    if ($minHours === null) {
        return '';
    }

    $parts = [];
    if (($constraints['label'] ?? '') !== '') {
        $parts[] = (string) $constraints['label'];
    }
    if ($requiredHours !== null) {
        $parts[] = '必要学習時間: 約' . $requiredHours . '時間';
    }
    if ($examDate !== null) {
        $parts[] = '目標日: ' . $examDate;
    }
    $parts[] = '推奨: 週' . $minHours . '時間以上';

    return implode(' / ', $parts);
}

function extractConstraintsFromMessages(array $messages): array
{
    $constraints = [
        'required_hours' => null,
        'exam_date' => null,
        'label' => null,
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

    $constraints['min_hours_per_week'] = calculateMinHoursPerWeek(
        is_int($constraints['required_hours']) ? $constraints['required_hours'] : null,
        is_string($constraints['exam_date']) ? $constraints['exam_date'] : null
    );

    return $constraints;
}

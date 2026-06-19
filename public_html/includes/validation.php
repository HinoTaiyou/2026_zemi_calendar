<?php
declare(strict_types=1);

const EVENT_TITLE_MAX_LENGTH = 255;
const EVENT_DURATION_MINUTES_MIN = 15;
const EVENT_DURATION_MINUTES_MAX = 480;

function scalarToStringOrNull(mixed $value): ?string
{
    if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
        return trim((string) $value);
    }

    return null;
}

function readInputString(array $source, string $key, string $default = ''): string
{
    if (!array_key_exists($key, $source)) {
        return $default;
    }

    return scalarToStringOrNull($source[$key]) ?? $default;
}

function readInputInt(array $source, string $key, int $default = 0): int
{
    $value = readInputString($source, $key, '');
    if ($value === '' || !preg_match('/^-?\d+$/', $value)) {
        return $default;
    }

    return (int) $value;
}

function hasStrictDateErrors(): bool
{
    $errors = DateTime::getLastErrors();
    if ($errors === false) {
        return false;
    }

    return ($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0;
}

function isStrictDateYmd(string $date): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }

    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    if ($parsed === false || hasStrictDateErrors()) {
        return false;
    }

    return $parsed->format('Y-m-d') === $date;
}

function isStrictTimeHm(string $time): bool
{
    if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
        return false;
    }

    [$hour, $minute] = array_map('intval', explode(':', $time));

    return $hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59;
}

function timeToMinutes(string $time): int
{
    [$hour, $minute] = array_map('intval', explode(':', $time));

    return $hour * 60 + $minute;
}

function minutesToTime(int $minutes): string
{
    return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
}

function timeRangesOverlap(int $startA, int $endA, int $startB, int $endB): bool
{
    return $startA < $endB && $endA > $startB;
}

function validateEventData(mixed $dateValue, mixed $timeValue, mixed $durationValue, mixed $titleValue): array
{
    $errors = [];

    $date = scalarToStringOrNull($dateValue);
    if ($date === null || $date === '') {
        $errors[] = '日付を入力してください。';
        $date = '';
    } elseif (!isStrictDateYmd($date)) {
        $errors[] = '日付は存在する日付を YYYY-MM-DD 形式で指定してください。';
    }

    $time = scalarToStringOrNull($timeValue);
    if ($time === null || $time === '') {
        $errors[] = '開始時刻を入力してください。';
        $time = '';
    } elseif (!isStrictTimeHm($time)) {
        $errors[] = '開始時刻は HH:MM 形式で指定してください。';
    }

    $durationString = scalarToStringOrNull($durationValue);
    if ($durationString === null || !preg_match('/^\d+$/', $durationString)) {
        $errors[] = '所要時間は分単位の数値で指定してください。';
        $durationMinutes = 0;
    } else {
        $durationMinutes = (int) $durationString;
        if ($durationMinutes < EVENT_DURATION_MINUTES_MIN || $durationMinutes > EVENT_DURATION_MINUTES_MAX) {
            $errors[] = '所要時間は15〜480分の範囲で指定してください。';
        }
    }

    $title = scalarToStringOrNull($titleValue);
    if ($title === null || $title === '') {
        $errors[] = 'タイトルを入力してください。';
        $title = '';
    } elseif (eventStringLength($title) > EVENT_TITLE_MAX_LENGTH) {
        $errors[] = 'タイトルは255文字以内で入力してください。';
    }

    if ($time !== '' && isStrictTimeHm($time) && $durationMinutes > 0) {
        $startMinutes = timeToMinutes($time);
        $endMinutes = $startMinutes + $durationMinutes;
        if ($endMinutes <= $startMinutes || $endMinutes > 24 * 60) {
            $errors[] = '終了時刻が同じ日付内で開始時刻より後になるように指定してください。';
        }
    }

    return [
        'valid' => $errors === [],
        'errors' => $errors,
        'event' => [
            'date' => $date,
            'time' => $time,
            'duration_minutes' => $durationMinutes,
            'title' => $title,
        ],
    ];
}

function eventStringLength(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value);
    }

    return strlen($value);
}

function normalizeAiIdempotencyKey(mixed $value): ?string
{
    $key = scalarToStringOrNull($value);
    if ($key === null || $key === '') {
        return null;
    }

    if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $key)) {
        return null;
    }

    return $key;
}

function validateEventPayload(array $payload): array
{
    $result = validateEventData(
        $payload['date'] ?? null,
        $payload['time'] ?? null,
        $payload['duration_minutes'] ?? null,
        $payload['title'] ?? null
    );

    if (!$result['valid']) {
        throw new InvalidArgumentException(implode(' ', $result['errors']));
    }

    $event = $result['event'];
    $event['ai_idempotency_key'] = normalizeAiIdempotencyKey($payload['ai_idempotency_key'] ?? null);

    return $event;
}

function validateEventList(array $events): array
{
    $validated = [];

    foreach ($events as $index => $event) {
        if (!is_array($event)) {
            throw new InvalidArgumentException(($index + 1) . '件目の予定形式が正しくありません。');
        }

        try {
            $validated[] = validateEventPayload($event);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException(($index + 1) . '件目の予定: ' . $e->getMessage());
        }
    }

    return $validated;
}

function eventStartMinutes(array $event): int
{
    return timeToMinutes((string) $event['time']);
}

function eventEndMinutes(array $event): int
{
    return eventStartMinutes($event) + (int) $event['duration_minutes'];
}

function formatEventRangeText(array $event): string
{
    return (string) $event['date'] . ' '
        . (string) $event['time'] . ' - '
        . minutesToTime(eventEndMinutes($event));
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/validation.php';

class EventConflictException extends RuntimeException
{
    private array $conflicts;

    public function __construct(array $conflicts)
    {
        parent::__construct('既存の予定と時間が重なっています。');
        $this->conflicts = $conflicts;
    }

    public function getConflicts(): array
    {
        return $this->conflicts;
    }
}

function mapEventRow(array $row): array
{
    $time = $row['event_time'] ?? '00:00:00';
    $date = $row['event_date'] ?? '';

    if ($date instanceof DateTimeInterface) {
        $date = $date->format('Y-m-d');
    } else {
        $date = substr((string) $date, 0, 10);
    }

    return [
        'id' => (int) $row['id'],
        'date' => $date,
        'time' => substr((string) $time, 0, 5),
        'duration_minutes' => (int) $row['duration_minutes'],
        'title' => $row['title'],
        'ai_idempotency_key' => $row['ai_idempotency_key'] ?? null,
        'source_type' => $row['source_type'] ?? null,
        'source_batch_id' => $row['source_batch_id'] ?? null,
        'source_label' => $row['source_label'] ?? null,
    ];
}

function getEventById(int $id): ?array
{
    $row = dbFetchOne('SELECT * FROM events WHERE id = $1', [$id]);

    return $row ? mapEventRow($row) : null;
}

function getEventsForDate(string $date): array
{
    $rows = dbFetchAll(
        'SELECT * FROM events WHERE event_date = $1 ORDER BY event_time ASC',
        [$date]
    );

    return array_map('mapEventRow', $rows);
}

function getEventsGroupedByDate(int $year, int $month): array
{
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = sprintf('%04d-%02d-%02d', $year, $month, (int) date('t', mktime(0, 0, 0, $month, 1, $year)));

    $rows = dbFetchAll(
        'SELECT * FROM events WHERE event_date BETWEEN $1 AND $2 ORDER BY event_date ASC, event_time ASC',
        [$start, $end]
    );

    $grouped = [];
    foreach ($rows as $row) {
        $event = mapEventRow($row);
        $grouped[$event['date']][] = $event;
    }

    return $grouped;
}

function addEvents(array $newEvents, bool $allowConflict = false): array
{
    if ($newEvents === []) {
        return ['inserted' => 0, 'skipped' => 0, 'total' => 0];
    }

    $validatedEvents = validateEventList($newEvents);
    $idempotencyKeys = array_values(array_filter(array_map(
        static fn(array $event): ?string => $event['ai_idempotency_key'] ?? null,
        $validatedEvents
    )));

    if (!$allowConflict) {
        $conflicts = findEventListConflicts($validatedEvents, null, $idempotencyKeys);
        if ($conflicts !== []) {
            throw new EventConflictException($conflicts);
        }
    }

    $conn = getDb();

    if (!pg_query($conn, 'BEGIN')) {
        throw new DatabaseException('DB_SQL_FAILED', dbUserMessage('DB_SQL_FAILED'));
    }

    try {
        $inserted = 0;
        $skipped = 0;

        foreach ($validatedEvents as $event) {
            $eventId = insertEventRow($event);
            if ($eventId > 0) {
                $inserted++;
            } else {
                $skipped++;
            }
        }

        if (!pg_query($conn, 'COMMIT')) {
            throw new DatabaseException('DB_SQL_FAILED', dbUserMessage('DB_SQL_FAILED'));
        }

        return [
            'inserted' => $inserted,
            'skipped' => $skipped,
            'total' => count($validatedEvents),
        ];
    } catch (Throwable $e) {
        pg_query($conn, 'ROLLBACK');
        throw $e;
    }
}

function insertEventRow(array $event): int
{
    $idempotencyKey = $event['ai_idempotency_key'] ?? null;
    $sourceType = $event['source_type'] ?? null;
    $sourceBatchId = $event['source_batch_id'] ?? null;
    $sourceLabel = $event['source_label'] ?? null;

    if ($idempotencyKey !== null) {
        $row = dbFetchOne(
            'INSERT INTO events (event_date, event_time, duration_minutes, title, ai_idempotency_key, source_type, source_batch_id, source_label)
             VALUES ($1, $2, $3, $4, $5, $6, $7, $8)
             ON CONFLICT (ai_idempotency_key) WHERE ai_idempotency_key IS NOT NULL
             DO NOTHING
             RETURNING id',
            [
                $event['date'],
                normalizeEventTime($event['time']),
                (int) $event['duration_minutes'],
                $event['title'],
                $idempotencyKey,
                $sourceType,
                $sourceBatchId,
                $sourceLabel,
            ]
        );

        return (int) ($row['id'] ?? 0);
    }

    $row = dbFetchOne(
        'INSERT INTO events (event_date, event_time, duration_minutes, title, source_type, source_batch_id, source_label)
         VALUES ($1, $2, $3, $4, $5, $6, $7)
         RETURNING id',
        [
            $event['date'],
            normalizeEventTime($event['time']),
            (int) $event['duration_minutes'],
            $event['title'],
            $sourceType,
            $sourceBatchId,
            $sourceLabel,
        ]
    );

    return (int) ($row['id'] ?? 0);
}

function createEvent(string $date, string $time, int $durationMinutes, string $title, bool $allowConflict = false): int
{
    $validation = validateEventData($date, $time, $durationMinutes, $title);
    if (!$validation['valid']) {
        throw new InvalidArgumentException(implode(' ', $validation['errors']));
    }

    $event = $validation['event'];
    $event['ai_idempotency_key'] = null;
    $event['source_type'] = 'manual';
    $event['source_batch_id'] = null;
    $event['source_label'] = null;

    if (!$allowConflict) {
        $conflicts = findEventConflicts($event);
        if ($conflicts !== []) {
            throw new EventConflictException($conflicts);
        }
    }

    return insertEventRow($event);
}

function updateEvent(int $id, string $date, string $time, int $durationMinutes, string $title, bool $allowConflict = false): bool
{
    $validation = validateEventData($date, $time, $durationMinutes, $title);
    if (!$validation['valid']) {
        throw new InvalidArgumentException(implode(' ', $validation['errors']));
    }

    $event = $validation['event'];
    $event['ai_idempotency_key'] = null;

    if (!$allowConflict) {
        $conflicts = findEventConflicts($event, $id);
        if ($conflicts !== []) {
            throw new EventConflictException($conflicts);
        }
    }

    $result = dbQuery(
        'UPDATE events
         SET event_date = $1,
             event_time = $2,
             duration_minutes = $3,
             title = $4
         WHERE id = $5',
        [
            $event['date'],
            normalizeEventTime($event['time']),
            (int) $event['duration_minutes'],
            $event['title'],
            $id,
        ]
    );

    return pg_affected_rows($result) > 0;
}

function deleteEvent(int $id): bool
{
    $result = dbQuery('DELETE FROM events WHERE id = $1', [$id]);

    return pg_affected_rows($result) > 0;
}

function findEventConflicts(array $event, ?int $excludeId = null, array $ignoreAiKeys = []): array
{
    $date = (string) ($event['date'] ?? '');
    if (!isStrictDateYmd($date)) {
        return [];
    }

    $start = eventStartMinutes($event);
    $end = eventEndMinutes($event);
    $existingEvents = getEventsForDate($date);
    $ignoreAiKeys = array_values(array_filter($ignoreAiKeys));
    $conflicts = [];

    foreach ($existingEvents as $existing) {
        if ($excludeId !== null && (int) $existing['id'] === $excludeId) {
            continue;
        }

        $existingKey = $existing['ai_idempotency_key'] ?? null;
        if ($existingKey !== null && in_array($existingKey, $ignoreAiKeys, true)) {
            continue;
        }

        if (timeRangesOverlap($start, $end, eventStartMinutes($existing), eventEndMinutes($existing))) {
            $conflicts[] = [
                'type' => 'existing',
                'event' => $event,
                'existing_event' => $existing,
            ];
        }
    }

    return $conflicts;
}

function findInternalEventConflicts(array $events): array
{
    $conflicts = [];
    $count = count($events);

    for ($i = 0; $i < $count; $i++) {
        for ($j = $i + 1; $j < $count; $j++) {
            if (($events[$i]['date'] ?? '') !== ($events[$j]['date'] ?? '')) {
                continue;
            }

            if (timeRangesOverlap(
                eventStartMinutes($events[$i]),
                eventEndMinutes($events[$i]),
                eventStartMinutes($events[$j]),
                eventEndMinutes($events[$j])
            )) {
                $conflicts[] = [
                    'type' => 'internal',
                    'event' => $events[$i],
                    'other_event' => $events[$j],
                ];
            }
        }
    }

    return $conflicts;
}

function findEventListConflicts(array $events, ?int $excludeId = null, array $ignoreAiKeys = []): array
{
    $conflicts = findInternalEventConflicts($events);

    foreach ($events as $event) {
        $conflicts = array_merge($conflicts, findEventConflicts($event, $excludeId, $ignoreAiKeys));
    }

    return $conflicts;
}

function formatConflictTarget(array $conflict): array
{
    if (($conflict['type'] ?? '') === 'internal') {
        return [
            'label' => '同じプラン内の予定',
            'event' => $conflict['other_event'] ?? [],
        ];
    }

    return [
        'label' => '既存予定',
        'event' => $conflict['existing_event'] ?? [],
    ];
}

function normalizeEventTime(string $time): string
{
    if (isStrictTimeHm($time)) {
        return $time . ':00';
    }

    return '09:00:00';
}

function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}

function formatEventTime(array $event): string
{
    $time = $event['time'] ?? '';
    $duration = (int) ($event['duration_minutes'] ?? 0);

    if ($time === '') {
        return '';
    }

    if ($duration <= 0) {
        return $time;
    }

    $start = DateTime::createFromFormat('H:i', $time);
    if ($start === false) {
        return $time;
    }

    $end = clone $start;
    $end->modify('+' . $duration . ' minutes');

    return $start->format('H:i') . ' - ' . $end->format('H:i');
}

function renderDayEventsHtml(array $events, int $limit = 2): string
{
    if ($events === []) {
        return '';
    }

    $html = '<div class="day-events">';
    $shown = array_slice($events, 0, $limit);

    foreach ($shown as $event) {
        $title = htmlspecialchars((string) ($event['title'] ?? ''), ENT_QUOTES, 'UTF-8');
        $time = htmlspecialchars((string) ($event['time'] ?? ''), ENT_QUOTES, 'UTF-8');
        $full = trim($time . ' ' . (string) ($event['title'] ?? ''));
        $label = $time !== '' ? $time . ' ' . $title : $title;
        $html .= '<span class="day-event" title="' . htmlspecialchars($full, ENT_QUOTES, 'UTF-8') . '">' . $label . '</span>';
    }

    $remaining = count($events) - count($shown);
    if ($remaining > 0) {
        $html .= '<span class="day-event-more">+' . $remaining . '件</span>';
    }

    $html .= '</div>';

    // Compact dot row used on narrow screens (CSS toggles visibility).
    $dotCount = min(count($events), 3);
    $html .= '<div class="day-events-dots" aria-hidden="true">';
    for ($i = 0; $i < $dotCount; $i++) {
        $html .= '<span></span>';
    }
    $html .= '</div>';

    return $html;
}

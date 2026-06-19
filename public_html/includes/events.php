<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

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

function addEvents(array $newEvents): void
{
    if ($newEvents === []) {
        return;
    }

    $conn = getDb();

    if (!pg_query($conn, 'BEGIN')) {
        throw new RuntimeException('トランザクション開始に失敗しました。');
    }

    try {
        foreach ($newEvents as $event) {
            dbQuery(
                'INSERT INTO events (event_date, event_time, duration_minutes, title)
                 VALUES ($1, $2, $3, $4)',
                [
                    $event['date'],
                    normalizeEventTime($event['time'] ?? '09:00'),
                    max(15, (int) ($event['duration_minutes'] ?? 30)),
                    trim((string) ($event['title'] ?? '')),
                ]
            );
        }

        if (!pg_query($conn, 'COMMIT')) {
            throw new RuntimeException('コミットに失敗しました。');
        }
    } catch (Throwable $e) {
        pg_query($conn, 'ROLLBACK');
        throw $e;
    }
}

function createEvent(string $date, string $time, int $durationMinutes, string $title): int
{
    $row = dbFetchOne(
        'INSERT INTO events (event_date, event_time, duration_minutes, title)
         VALUES ($1, $2, $3, $4)
         RETURNING id',
        [
            $date,
            normalizeEventTime($time),
            max(15, $durationMinutes),
            trim($title),
        ]
    );

    return (int) ($row['id'] ?? 0);
}

function updateEvent(int $id, string $date, string $time, int $durationMinutes, string $title): bool
{
    $result = dbQuery(
        'UPDATE events
         SET event_date = $1,
             event_time = $2,
             duration_minutes = $3,
             title = $4
         WHERE id = $5',
        [
            $date,
            normalizeEventTime($time),
            max(15, $durationMinutes),
            trim($title),
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

function normalizeEventTime(string $time): string
{
    if (preg_match('/^\d{1,2}:\d{2}$/', $time)) {
        $parts = explode(':', $time);
        return sprintf('%02d:%02d:00', (int) $parts[0], (int) $parts[1]);
    }

    if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
        return $time;
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
        $html .= '<span class="day-event">' . $title . '</span>';
    }

    $remaining = count($events) - count($shown);
    if ($remaining > 0) {
        $html .= '<span class="day-event-more">+' . $remaining . '</span>';
    }

    $html .= '</div>';

    return $html;
}

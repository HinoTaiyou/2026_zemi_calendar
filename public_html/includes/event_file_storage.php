<?php
declare(strict_types=1);

function eventFileStoragePath(): string
{
    $default = dirname(__DIR__) . '/data/events.json';
    $path = appConfigValue('EVENT_STORAGE_PATH', $default, false);
    if ($path === '') {
        return $default;
    }

    if (preg_match('/^(\/|[A-Za-z]:[\/\\\\])/', $path) === 1) {
        return $path;
    }

    return dirname(__DIR__) . '/' . $path;
}

function normalizeStoredEvent(array $event): array
{
    return [
        'id' => (int) ($event['id'] ?? 0),
        'date' => substr((string) ($event['date'] ?? $event['event_date'] ?? ''), 0, 10),
        'time' => substr((string) ($event['time'] ?? $event['event_time'] ?? '00:00'), 0, 5),
        'duration_minutes' => (int) ($event['duration_minutes'] ?? 30),
        'title' => (string) ($event['title'] ?? ''),
        'ai_idempotency_key' => ($event['ai_idempotency_key'] ?? null) !== null ? (string) $event['ai_idempotency_key'] : null,
        'source_type' => ($event['source_type'] ?? null) !== null ? (string) $event['source_type'] : null,
        'source_batch_id' => ($event['source_batch_id'] ?? null) !== null ? (string) $event['source_batch_id'] : null,
        'source_label' => ($event['source_label'] ?? null) !== null ? (string) $event['source_label'] : null,
    ];
}

function eventSortForDisplay(array $a, array $b): int
{
    return [$a['date'] ?? '', $a['time'] ?? '', (int) ($a['id'] ?? 0)]
        <=> [$b['date'] ?? '', $b['time'] ?? '', (int) ($b['id'] ?? 0)];
}

function eventFileInitialData(): array
{
    return ['next_id' => 1, 'events' => []];
}

function normalizeEventFileData(array $data): array
{
    $events = [];
    $maxId = 0;
    foreach (($data['events'] ?? []) as $event) {
        if (!is_array($event)) {
            continue;
        }
        $normalized = normalizeStoredEvent($event);
        if ($normalized['id'] <= 0 || !isStrictDateYmd($normalized['date']) || !isStrictTimeHm($normalized['time'])) {
            continue;
        }
        $events[] = $normalized;
        $maxId = max($maxId, $normalized['id']);
    }
    usort($events, 'eventSortForDisplay');

    return [
        'next_id' => max((int) ($data['next_id'] ?? 1), $maxId + 1),
        'events' => $events,
    ];
}

function readEventFileHandle($handle): array
{
    rewind($handle);
    $json = stream_get_contents($handle);
    if ($json === false || trim($json) === '') {
        return eventFileInitialData();
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        throw new DatabaseException('STORAGE_FILE_FAILED', dbUserMessage('STORAGE_FILE_FAILED'));
    }

    return normalizeEventFileData($decoded);
}

function eventFileEnsureDirectory(string $path): void
{
    $dir = dirname($path);
    if (is_dir($dir)) {
        return;
    }

    if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new DatabaseException('STORAGE_FILE_FAILED', dbUserMessage('STORAGE_FILE_FAILED'));
    }
}

function eventFileReadData(): array
{
    $path = eventFileStoragePath();
    eventFileEnsureDirectory($path);
    $handle = fopen($path, 'c+');
    if ($handle === false) {
        throw new DatabaseException('STORAGE_FILE_FAILED', dbUserMessage('STORAGE_FILE_FAILED'));
    }

    try {
        if (!flock($handle, LOCK_SH)) {
            throw new DatabaseException('STORAGE_FILE_FAILED', dbUserMessage('STORAGE_FILE_FAILED'));
        }
        $data = readEventFileHandle($handle);
        flock($handle, LOCK_UN);
        return $data;
    } finally {
        fclose($handle);
    }
}

function eventFileWriteData(array $data): void
{
    $path = eventFileStoragePath();
    eventFileEnsureDirectory($path);
    $handle = fopen($path, 'c+');
    if ($handle === false) {
        throw new DatabaseException('STORAGE_FILE_FAILED', dbUserMessage('STORAGE_FILE_FAILED'));
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new DatabaseException('STORAGE_FILE_FAILED', dbUserMessage('STORAGE_FILE_FAILED'));
        }
        $normalized = normalizeEventFileData($data);
        $json = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new DatabaseException('STORAGE_FILE_FAILED', dbUserMessage('STORAGE_FILE_FAILED'));
        }
        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $json . "\n") === false || !fflush($handle)) {
            throw new DatabaseException('STORAGE_FILE_FAILED', dbUserMessage('STORAGE_FILE_FAILED'));
        }
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }
}

function eventFileMutate(callable $callback): mixed
{
    $path = eventFileStoragePath();
    eventFileEnsureDirectory($path);
    $handle = fopen($path, 'c+');
    if ($handle === false) {
        throw new DatabaseException('STORAGE_FILE_FAILED', dbUserMessage('STORAGE_FILE_FAILED'));
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new DatabaseException('STORAGE_FILE_FAILED', dbUserMessage('STORAGE_FILE_FAILED'));
        }
        $data = readEventFileHandle($handle);
        $result = $callback($data);
        $normalized = normalizeEventFileData($data);
        $json = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new DatabaseException('STORAGE_FILE_FAILED', dbUserMessage('STORAGE_FILE_FAILED'));
        }
        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $json . "\n") === false || !fflush($handle)) {
            throw new DatabaseException('STORAGE_FILE_FAILED', dbUserMessage('STORAGE_FILE_FAILED'));
        }
        flock($handle, LOCK_UN);
        return $result;
    } finally {
        fclose($handle);
    }
}

function eventFileAllEvents(): array
{
    $data = eventFileReadData();
    $events = $data['events'];
    usort($events, 'eventSortForDisplay');

    return $events;
}

function eventFileFindById(int $id): ?array
{
    foreach (eventFileAllEvents() as $event) {
        if ((int) $event['id'] === $id) {
            return $event;
        }
    }

    return null;
}

function eventFileEventsForDate(string $date): array
{
    $events = array_values(array_filter(
        eventFileAllEvents(),
        static fn(array $event): bool => ($event['date'] ?? '') === $date
    ));
    usort($events, 'eventSortForDisplay');

    return $events;
}

function eventFileEventsForRange(string $start, string $end): array
{
    $events = array_values(array_filter(eventFileAllEvents(), static function (array $event) use ($start, $end): bool {
        $date = (string) ($event['date'] ?? '');
        return $date >= $start && $date <= $end;
    }));
    usort($events, 'eventSortForDisplay');

    return $events;
}

function eventFileInsertEvent(array $event): int
{
    return (int) eventFileMutate(static function (array &$data) use ($event): int {
        $idempotencyKey = $event['ai_idempotency_key'] ?? null;
        if ($idempotencyKey !== null) {
            foreach ($data['events'] as $stored) {
                if (($stored['ai_idempotency_key'] ?? null) === $idempotencyKey) {
                    return 0;
                }
            }
        }

        $id = (int) $data['next_id'];
        $stored = normalizeStoredEvent($event);
        $stored['id'] = $id;
        $data['events'][] = $stored;
        $data['next_id'] = $id + 1;

        return $id;
    });
}

function eventFileUpdateEvent(int $id, array $event): bool
{
    return (bool) eventFileMutate(static function (array &$data) use ($id, $event): bool {
        foreach ($data['events'] as $index => $stored) {
            if ((int) ($stored['id'] ?? 0) !== $id) {
                continue;
            }

            $updated = normalizeStoredEvent(array_merge($stored, $event));
            $updated['id'] = $id;
            $data['events'][$index] = $updated;

            return true;
        }

        return false;
    });
}

function eventFileDeleteEvent(int $id): bool
{
    return (bool) eventFileMutate(static function (array &$data) use ($id): bool {
        $before = count($data['events']);
        $data['events'] = array_values(array_filter(
            $data['events'],
            static fn(array $event): bool => (int) ($event['id'] ?? 0) !== $id
        ));

        return count($data['events']) !== $before;
    });
}

function eventFileMatchesBulkFilter(array $event, array $filter, DateTimeImmutable $now): bool
{
    $date = (string) ($event['date'] ?? '');
    if ($date === '') {
        return false;
    }
    if ($filter['start_date'] !== null && $date < $filter['start_date']) {
        return false;
    }
    if ($filter['end_date'] !== null && $date > $filter['end_date']) {
        return false;
    }
    if ($filter['future_only'] && $date < $now->format('Y-m-d')) {
        return false;
    }
    if ($filter['weekdays'] !== []) {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || !in_array((int) $parsed->format('N'), $filter['weekdays'], true)) {
            return false;
        }
    }
    if ($filter['keyword'] !== '' && mb_stripos((string) ($event['title'] ?? ''), $filter['keyword']) === false) {
        return false;
    }

    $sourceType = $event['source_type'] ?? null;
    switch ($filter['source']) {
        case 'manual':
        case 'study_plan':
        case 'other_ai':
            if ($sourceType !== $filter['source']) {
                return false;
            }
            break;
        case 'unknown':
            if ($sourceType !== null) {
                return false;
            }
            break;
    }

    return $filter['batch_id'] === null || ($event['source_batch_id'] ?? null) === $filter['batch_id'];
}

function eventFileFilterEvents(array $filter, DateTimeImmutable $now): array
{
    $events = array_values(array_filter(
        eventFileAllEvents(),
        static fn(array $event): bool => eventFileMatchesBulkFilter($event, $filter, $now)
    ));
    usort($events, 'eventSortForDisplay');

    return $events;
}

function eventFileDeleteByFilter(array $filter, DateTimeImmutable $now): int
{
    return (int) eventFileMutate(static function (array &$data) use ($filter, $now): int {
        $deleted = 0;
        $kept = [];
        foreach ($data['events'] as $event) {
            $normalized = normalizeStoredEvent($event);
            if (eventFileMatchesBulkFilter($normalized, $filter, $now)) {
                $deleted++;
                continue;
            }
            $kept[] = $normalized;
        }
        $data['events'] = $kept;

        return $deleted;
    });
}

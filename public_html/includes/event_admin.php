<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/events.php';
require_once __DIR__ . '/study_planner.php';

/*
 * Bulk event management: validate a filter, preview the matching events, and
 * delete them safely. Deletion always re-derives target rows from the filter on
 * the server (never trusts client-sent ids), runs in a transaction, and is
 * capped. There is no "delete everything": an empty filter is rejected.
 */

const BULK_DELETE_MAX = 500;
const BULK_PREVIEW_ROWS = 50;
const BULK_STRONG_CONFIRM_THRESHOLD = 50;
const BULK_KEYWORD_MAX_LENGTH = 100;
const BULK_SOURCE_OPTIONS = ['any', 'manual', 'study_plan', 'other_ai', 'unknown'];
const BULK_CONFIRM_WORD = '削除';

function bulkFilterDefaults(): array
{
    return [
        'start_date' => null,
        'end_date' => null,
        'weekdays' => [],
        'keyword' => '',
        'source' => 'any',
        'batch_id' => null,
        'future_only' => false,
    ];
}

function normalizeBulkFilter(array $in, DateTimeImmutable $now): array
{
    $filter = bulkFilterDefaults();

    $start = scalarToStringOrNull($in['start_date'] ?? null);
    if ($start !== null && isStrictDateYmd($start)) {
        $filter['start_date'] = $start;
    }
    $end = scalarToStringOrNull($in['end_date'] ?? null);
    if ($end !== null && isStrictDateYmd($end)) {
        $filter['end_date'] = $end;
    }

    $weekdays = $in['weekdays'] ?? [];
    if (is_array($weekdays)) {
        $seen = [];
        foreach ($weekdays as $day) {
            $n = clampInt($day, 1, 7);
            if ($n !== null && !isset($seen[$n])) {
                $seen[$n] = true;
                $filter['weekdays'][] = $n;
            }
        }
        sort($filter['weekdays']);
    }

    $keyword = scalarToStringOrNull($in['keyword'] ?? null);
    if ($keyword !== null && $keyword !== '') {
        $filter['keyword'] = mb_substr($keyword, 0, BULK_KEYWORD_MAX_LENGTH);
    }

    $source = scalarToStringOrNull($in['source'] ?? null);
    if ($source !== null && in_array($source, BULK_SOURCE_OPTIONS, true)) {
        $filter['source'] = $source;
    }

    $batch = normalizeSourceBatchId($in['batch_id'] ?? null);
    if ($batch !== null) {
        $filter['batch_id'] = $batch;
    }

    $future = $in['future_only'] ?? null;
    $filter['future_only'] = ($future === true || $future === '1' || $future === 1 || $future === 'on');

    return $filter;
}

function isBulkFilterEmpty(array $filter): bool
{
    return $filter['start_date'] === null
        && $filter['end_date'] === null
        && $filter['weekdays'] === []
        && $filter['keyword'] === ''
        && $filter['source'] === 'any'
        && $filter['batch_id'] === null
        && $filter['future_only'] === false;
}

/** Escape an ILIKE search term so user % / _ / \ are treated literally. */
function escapeLikeTerm(string $term): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
}

/**
 * Build a parameterized WHERE clause. Weekdays are embedded as integer literals
 * (already strictly validated to 1..7, no injection risk); everything else uses
 * placeholders.
 */
function buildBulkFilterWhere(array $filter, DateTimeImmutable $now): array
{
    $clauses = [];
    $params = [];
    $i = 1;

    if ($filter['start_date'] !== null) {
        $clauses[] = 'event_date >= $' . $i++;
        $params[] = $filter['start_date'];
    }
    if ($filter['end_date'] !== null) {
        $clauses[] = 'event_date <= $' . $i++;
        $params[] = $filter['end_date'];
    }
    if ($filter['future_only']) {
        $clauses[] = 'event_date >= $' . $i++;
        $params[] = $now->format('Y-m-d');
    }
    if ($filter['weekdays'] !== []) {
        $ints = implode(',', array_map('intval', $filter['weekdays']));
        $clauses[] = 'EXTRACT(ISODOW FROM event_date)::int IN (' . $ints . ')';
    }
    if ($filter['keyword'] !== '') {
        $clauses[] = 'title ILIKE $' . $i++ . " ESCAPE '\\'";
        $params[] = '%' . escapeLikeTerm($filter['keyword']) . '%';
    }
    switch ($filter['source']) {
        case 'manual':
        case 'study_plan':
        case 'other_ai':
            $clauses[] = 'source_type = $' . $i++;
            $params[] = $filter['source'];
            break;
        case 'unknown':
            $clauses[] = 'source_type IS NULL';
            break;
    }
    if ($filter['batch_id'] !== null) {
        $clauses[] = 'source_batch_id = $' . $i++;
        $params[] = $filter['batch_id'];
    }

    // Empty filter must never match everything.
    $where = $clauses === [] ? '1=0' : implode(' AND ', $clauses);

    return [$where, $params];
}

function bulkFilterFingerprint(array $filter): string
{
    return hash('sha256', json_encode($filter, JSON_UNESCAPED_UNICODE) ?: '');
}

/**
 * Strong confirmation (typed word) is required for large or broad deletes:
 * 50+ rows, or no bounded date range without a specific batch selected.
 */
function bulkFilterRequiresStrongConfirm(array $filter, int $count): bool
{
    if ($count >= BULK_STRONG_CONFIRM_THRESHOLD) {
        return true;
    }
    $bounded = $filter['start_date'] !== null && $filter['end_date'] !== null;
    $specificBatch = $filter['batch_id'] !== null;

    return !$bounded && !$specificBatch;
}

function bulkSourceLabel(string $source): string
{
    return [
        'any' => 'すべて',
        'manual' => '手動予定',
        'study_plan' => 'AI学習予定',
        'other_ai' => 'その他AI予定',
        'unknown' => '登録元不明',
    ][$source] ?? $source;
}

function bulkFilterSummaryText(array $filter): string
{
    $parts = [];

    if ($filter['start_date'] !== null && $filter['end_date'] !== null) {
        $parts[] = '期間: ' . $filter['start_date'] . '〜' . $filter['end_date'];
    } elseif ($filter['start_date'] !== null) {
        $parts[] = $filter['start_date'] . ' 以降';
    } elseif ($filter['end_date'] !== null) {
        $parts[] = $filter['end_date'] . ' 以前';
    }
    if ($filter['future_only']) {
        $parts[] = '今日以降';
    }
    if ($filter['weekdays'] !== []) {
        $parts[] = '曜日: ' . implode('・', array_map(
            static fn(int $d): string => (STUDY_WEEKDAYS_JA[$d] ?? (string) $d),
            $filter['weekdays']
        ));
    }
    if ($filter['keyword'] !== '') {
        $parts[] = 'タイトルに「' . $filter['keyword'] . '」';
    }
    if ($filter['source'] !== 'any') {
        $parts[] = '登録元: ' . bulkSourceLabel($filter['source']);
    }
    if ($filter['batch_id'] !== null) {
        $parts[] = '指定の学習プラン';
    }

    return $parts === [] ? '条件なし' : implode(' / ', $parts);
}

// --------------------------------------------------------------------- DB ----

function previewBulkEvents(array $filter): array
{
    [$where, $params] = buildBulkFilterWhere($filter, appNow());

    $agg = dbFetchOne(
        "SELECT COUNT(*) AS c, MIN(event_date) AS f, MAX(event_date) AS l, COALESCE(SUM(duration_minutes),0) AS tot
         FROM events WHERE {$where}",
        $params
    );

    $bySource = [];
    foreach (dbFetchAll(
        "SELECT COALESCE(source_type, 'unknown') AS st, COUNT(*) AS c FROM events WHERE {$where} GROUP BY 1 ORDER BY 1",
        $params
    ) as $row) {
        $bySource[(string) $row['st']] = (int) $row['c'];
    }

    $byWeekday = [];
    foreach (dbFetchAll(
        "SELECT EXTRACT(ISODOW FROM event_date)::int AS wd, COUNT(*) AS c FROM events WHERE {$where} GROUP BY 1 ORDER BY 1",
        $params
    ) as $row) {
        $byWeekday[(int) $row['wd']] = (int) $row['c'];
    }

    $rows = dbFetchAll(
        "SELECT * FROM events WHERE {$where} ORDER BY event_date ASC, event_time ASC LIMIT " . BULK_PREVIEW_ROWS,
        $params
    );

    $first = $agg['f'] ?? null;
    $last = $agg['l'] ?? null;

    return [
        'count' => (int) ($agg['c'] ?? 0),
        'first' => $first !== null ? substr((string) $first, 0, 10) : null,
        'last' => $last !== null ? substr((string) $last, 0, 10) : null,
        'total_minutes' => (int) ($agg['tot'] ?? 0),
        'by_source' => $bySource,
        'by_weekday' => $byWeekday,
        'rows' => array_map('mapEventRow', $rows),
    ];
}

function countBulkEvents(array $filter): int
{
    [$where, $params] = buildBulkFilterWhere($filter, appNow());
    $row = dbFetchOne("SELECT COUNT(*) AS c FROM events WHERE {$where}", $params);

    return (int) ($row['c'] ?? 0);
}

/**
 * Delete the events matching the filter, re-deriving the target ids on the
 * server. Refuses to exceed the cap and runs in a single transaction so a
 * failure leaves nothing partially deleted.
 */
function deleteBulkEvents(array $filter): array
{
    [$where, $params] = buildBulkFilterWhere($filter, appNow());

    $count = countBulkEvents($filter);
    if ($count === 0) {
        return ['deleted' => 0];
    }
    if ($count > BULK_DELETE_MAX) {
        throw new RuntimeException('対象が' . BULK_DELETE_MAX . '件を超えています。条件を絞り込んでください。');
    }

    $conn = getDb();
    if (!pg_query($conn, 'BEGIN')) {
        throw new DatabaseException('DB_SQL_FAILED', dbUserMessage('DB_SQL_FAILED'));
    }

    try {
        $result = dbQuery(
            "DELETE FROM events WHERE id IN (SELECT id FROM events WHERE {$where})",
            $params
        );
        $deleted = pg_affected_rows($result);

        if (!pg_query($conn, 'COMMIT')) {
            throw new DatabaseException('DB_SQL_FAILED', dbUserMessage('DB_SQL_FAILED'));
        }

        return ['deleted' => $deleted];
    } catch (Throwable $e) {
        pg_query($conn, 'ROLLBACK');
        throw $e;
    }
}

function listStudyBatches(): array
{
    $rows = dbFetchAll(
        "SELECT source_batch_id AS id, MAX(source_label) AS label, COUNT(*) AS c,
                MIN(event_date) AS f, MAX(event_date) AS l
         FROM events
         WHERE source_type = 'study_plan' AND source_batch_id IS NOT NULL
         GROUP BY source_batch_id
         ORDER BY MAX(event_date) DESC, MAX(source_label) ASC",
        []
    );

    return array_map(static function (array $row): array {
        return [
            'id' => (string) $row['id'],
            'label' => (string) ($row['label'] ?? 'AI学習プラン'),
            'count' => (int) $row['c'],
            'first' => substr((string) ($row['f'] ?? ''), 0, 10),
            'last' => substr((string) ($row['l'] ?? ''), 0, 10),
        ];
    }, $rows);
}

// -------------------------------------------------------------------- CSV ----

/** Escape one CSV field (RFC 4180) and neutralize spreadsheet formula injection. */
function csvCell(string $value): string
{
    if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        $value = "'" . $value;
    }
    if (preg_match('/["\n\r,]/', $value)) {
        $value = '"' . str_replace('"', '""', $value) . '"';
    }

    return $value;
}

function bulkCsvString(array $filter): string
{
    [$where, $params] = buildBulkFilterWhere($filter, appNow());
    $rows = dbFetchAll(
        "SELECT * FROM events WHERE {$where} ORDER BY event_date ASC, event_time ASC LIMIT " . BULK_DELETE_MAX,
        $params
    );

    $columns = ['id', 'date', 'time', 'duration_minutes', 'title', 'source_type', 'source_label', 'source_batch_id'];
    $lines = [implode(',', $columns)];

    foreach (array_map('mapEventRow', $rows) as $event) {
        $lines[] = implode(',', [
            csvCell((string) $event['id']),
            csvCell((string) $event['date']),
            csvCell((string) $event['time']),
            csvCell((string) $event['duration_minutes']),
            csvCell((string) $event['title']),
            csvCell((string) ($event['source_type'] ?? '')),
            csvCell((string) ($event['source_label'] ?? '')),
            csvCell((string) ($event['source_batch_id'] ?? '')),
        ]);
    }

    return implode("\r\n", $lines) . "\r\n";
}

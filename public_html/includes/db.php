<?php
declare(strict_types=1);

function getDb()
{
    static $conn = null;

    if ($conn !== null) {
        return $conn;
    }

    if (!function_exists('pg_connect')) {
        throw new RuntimeException('PostgreSQL 拡張（pg_connect）が有効ではありません。');
    }

    if (DB_PASS === '') {
        throw new RuntimeException(
            'config.php の DB_PASS が未設定です。public_html/config.php を確認してください。'
        );
    }

    $dsn = sprintf(
        'host=%s user=%s password=%s dbname=%s',
        DB_HOST,
        DB_USER,
        DB_PASS,
        DB_NAME
    );

    $conn = @pg_connect($dsn);
    if ($conn === false) {
        throw new RuntimeException(
            'データベースに接続できませんでした。config.php の DB 設定を確認してください。'
        );
    }

    ensureEventsTable($conn);

    return $conn;
}

function ensureEventsTable($conn): void
{
    static $initialized = false;

    if ($initialized) {
        return;
    }

    $check = pg_query_params(
        $conn,
        'SELECT to_regclass($1) IS NOT NULL AS ok',
        ['public.events']
    );

    if ($check === false) {
        throw new RuntimeException('テーブル確認エラー: ' . pg_last_error($conn));
    }

    $row = pg_fetch_assoc($check);
    if ($row && $row['ok'] === 't') {
        $initialized = true;
        return;
    }

    $createTable = <<<'SQL'
CREATE TABLE events (
  id SERIAL PRIMARY KEY,
  event_date DATE NOT NULL,
  event_time TIME NOT NULL,
  duration_minutes INT NOT NULL DEFAULT 30,
  title VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)
SQL;

    if (pg_query($conn, $createTable) === false) {
        throw new RuntimeException('events テーブル作成エラー: ' . pg_last_error($conn));
    }

    pg_query($conn, 'CREATE INDEX IF NOT EXISTS idx_events_date ON events (event_date)');

    $initialized = true;
}

function dbQuery(string $sql, array $params = [])
{
    if ($params === []) {
        $result = pg_query(getDb(), $sql);
    } else {
        $result = pg_query_params(getDb(), $sql, $params);
    }

    if ($result === false) {
        throw new RuntimeException('クエリ実行エラー: ' . pg_last_error(getDb()));
    }

    return $result;
}

function dbFetchAll(string $sql, array $params = []): array
{
    $result = dbQuery($sql, $params);
    $rows = [];

    while ($row = pg_fetch_assoc($result)) {
        $rows[] = $row;
    }

    return $rows;
}

function dbFetchOne(string $sql, array $params = []): ?array
{
    $rows = dbFetchAll($sql, $params);

    return $rows[0] ?? null;
}

function dbExec(string $sql, array $params = []): bool
{
    dbQuery($sql, $params);

    return true;
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/app_config.php';

class DatabaseException extends RuntimeException
{
    private string $dbErrorCode;
    private array $context;

    public function __construct(string $dbErrorCode, string $message, array $context = [])
    {
        parent::__construct($message);
        $this->dbErrorCode = $dbErrorCode;
        $this->context = $context;
    }

    public function getDbErrorCode(): string
    {
        return $this->dbErrorCode;
    }

    public function getContext(): array
    {
        return $this->context;
    }
}

function dbUserMessage(string $errorCode): string
{
    return match ($errorCode) {
        'DB_CONFIG_MISSING' => 'データベース設定が不足しています。管理者に確認してください。',
        'DB_EXTENSION_MISSING' => 'データベース機能が利用できません。管理者に確認してください。',
        'DB_CONNECTION_FAILED' => 'データベースに接続できませんでした。管理者に確認してください。',
        'STORAGE_FILE_FAILED' => '予定データを保存できませんでした。管理者に確認してください。',
        default => 'データベース処理に失敗しました。',
    };
}

function publicDatabaseErrorMessage(Throwable $e): string
{
    if ($e instanceof DatabaseException) {
        return $e->getMessage();
    }

    return dbUserMessage('DB_SQL_FAILED');
}

function getDbConfig(): array
{
    return [
        'host' => appConfigValue('DB_HOST', 'localhost'),
        'port' => appConfigValue('DB_PORT'),
        'user' => appConfigValue('DB_USER'),
        'password' => appConfigValue('DB_PASS', '', false),
        'dbname' => appConfigValue('DB_NAME'),
    ];
}

function eventStorageDriver(): string
{
    return strtolower(appConfigValue('STORAGE_DRIVER', 'pgsql'));
}

function eventFileStorageEnabled(): bool
{
    return in_array(eventStorageDriver(), ['file', 'json'], true);
}

function getMissingDbConfigKeys(array $config): array
{
    $missing = [];

    foreach (['host', 'user', 'password', 'dbname'] as $key) {
        if ($config[$key] === '') {
            $missing[] = match ($key) {
                'host' => 'DB_HOST',
                'user' => 'DB_USER',
                'password' => 'DB_PASS',
                default => 'DB_NAME',
            };
        }
    }

    return $missing;
}

function getDb()
{
    static $conn = null;

    if ($conn !== null) {
        return $conn;
    }

    if (!function_exists('pg_connect')) {
        throw new DatabaseException('DB_EXTENSION_MISSING', dbUserMessage('DB_EXTENSION_MISSING'));
    }

    $config = getDbConfig();
    $missingKeys = getMissingDbConfigKeys($config);
    if ($missingKeys !== []) {
        throw new DatabaseException(
            'DB_CONFIG_MISSING',
            dbUserMessage('DB_CONFIG_MISSING'),
            ['missing' => $missingKeys]
        );
    }

    $dsnParts = [
        'host=' . $config['host'],
        'user=' . $config['user'],
        'password=' . $config['password'],
        'dbname=' . $config['dbname'],
    ];
    if ($config['port'] !== '') {
        $dsnParts[] = 'port=' . $config['port'];
    }

    $conn = @pg_connect(implode(' ', $dsnParts));
    if ($conn === false) {
        throw new DatabaseException('DB_CONNECTION_FAILED', dbUserMessage('DB_CONNECTION_FAILED'));
    }

    ensureEventsTable($conn);
    ensureAdoptedPlansTable($conn);

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
        throw new DatabaseException('DB_SQL_FAILED', dbUserMessage('DB_SQL_FAILED'));
    }

    $row = pg_fetch_assoc($check);
    if ($row && $row['ok'] === 't') {
        ensureEventsSchema($conn);
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
  ai_idempotency_key VARCHAR(64) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)
SQL;

    if (pg_query($conn, $createTable) === false) {
        throw new DatabaseException('DB_SQL_FAILED', dbUserMessage('DB_SQL_FAILED'));
    }

    pg_query($conn, 'CREATE INDEX IF NOT EXISTS idx_events_date ON events (event_date)');
    ensureEventsSchema($conn);

    $initialized = true;
}

function ensureEventsSchema($conn): void
{
    $statements = [
        'ALTER TABLE events ADD COLUMN IF NOT EXISTS ai_idempotency_key VARCHAR(64) NULL',
        'CREATE INDEX IF NOT EXISTS idx_events_date ON events (event_date)',
        'CREATE INDEX IF NOT EXISTS idx_events_date_time ON events (event_date, event_time)',
        'CREATE UNIQUE INDEX IF NOT EXISTS idx_events_ai_idempotency_key
            ON events (ai_idempotency_key)
            WHERE ai_idempotency_key IS NOT NULL',
        'ALTER TABLE events ADD COLUMN IF NOT EXISTS adopted_plan_id INT NULL',
    ];

    foreach ($statements as $sql) {
        if (pg_query($conn, $sql) === false) {
            throw new DatabaseException('DB_SQL_FAILED', dbUserMessage('DB_SQL_FAILED'));
        }
    }
}

function ensureAdoptedPlansTable($conn): void
{
    static $initialized = false;

    if ($initialized) {
        return;
    }

    $check = pg_query_params(
        $conn,
        'SELECT to_regclass($1) IS NOT NULL AS ok',
        ['public.adopted_plans']
    );

    if ($check === false) {
        throw new DatabaseException('DB_SQL_FAILED', dbUserMessage('DB_SQL_FAILED'));
    }

    $row = pg_fetch_assoc($check);
    if (!$row || $row['ok'] !== 't') {
        $createTable = <<<'SQL'
CREATE TABLE adopted_plans (
  id SERIAL PRIMARY KEY,
  plan_id VARCHAR(10) NOT NULL,
  plan_name VARCHAR(255) NOT NULL,
  plan_summary TEXT,
  constraints JSONB,
  adopted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  follow_up_due_at TIMESTAMP NOT NULL,
  follow_up_done_at TIMESTAMP NULL,
  review_fit VARCHAR(20) NULL,
  review_adjustment VARCHAR(20) NULL,
  review_note TEXT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active'
)
SQL;

        if (pg_query($conn, $createTable) === false) {
            throw new DatabaseException('DB_SQL_FAILED', dbUserMessage('DB_SQL_FAILED'));
        }
    }

    $statements = [
        'CREATE INDEX IF NOT EXISTS idx_adopted_plans_status_follow_up
            ON adopted_plans (status, follow_up_due_at)',
        'ALTER TABLE events ADD COLUMN IF NOT EXISTS adopted_plan_id INT NULL',
    ];

    foreach ($statements as $sql) {
        if (pg_query($conn, $sql) === false) {
            throw new DatabaseException('DB_SQL_FAILED', dbUserMessage('DB_SQL_FAILED'));
        }
    }

    $initialized = true;
}

function dbQuery(string $sql, array $params = [])
{
    $conn = getDb();
    if ($params === []) {
        $result = pg_query($conn, $sql);
    } else {
        $result = pg_query_params($conn, $sql, $params);
    }

    if ($result === false) {
        throw new DatabaseException('DB_SQL_FAILED', dbUserMessage('DB_SQL_FAILED'));
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

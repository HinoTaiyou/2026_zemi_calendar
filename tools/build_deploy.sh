#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SOURCE_DIR="$ROOT_DIR/public_html"
DEPLOY_DIR="$ROOT_DIR/deploy"
DEPLOY_PUBLIC="$DEPLOY_DIR/public_html"

if [[ ! -d "$SOURCE_DIR" ]]; then
  echo "source public_html not found" >&2
  exit 1
fi

case "$DEPLOY_PUBLIC" in
  "$ROOT_DIR"/deploy/public_html) ;;
  *)
    echo "refusing to remove unexpected deploy path" >&2
    exit 1
    ;;
esac

rm -rf "$DEPLOY_PUBLIC"
mkdir -p "$DEPLOY_PUBLIC"

rsync -a \
  --exclude='.git' \
  --exclude='.gitignore' \
  --exclude='.DS_Store' \
  --exclude='.env' \
  --exclude='.env.*' \
  --exclude='config.php' \
  --exclude='config.local.php' \
  --exclude='config.example.php' \
  --exclude='*.log' \
  --exclude='*.bak' \
  --exclude='*.backup' \
  --exclude='*~' \
  "$SOURCE_DIR"/ "$DEPLOY_PUBLIC"/

cat > "$DEPLOY_PUBLIC/config.server.example.php" <<'PHP'
<?php
declare(strict_types=1);

// Keep this file as a template and create config.php on the server.
// Do not commit config.php or paste it into chats, issues, or PRs.
return [
    'GEMINI_API_KEY' => '',
    'GEMINI_MODEL' => 'gemini-3.1-flash-lite',
    'STORAGE_DRIVER' => 'pgsql',
    'EVENT_STORAGE_PATH' => __DIR__ . '/data/events.json',
    'DB_HOST' => '',
    'DB_PORT' => '5432',
    'DB_USER' => '',
    'DB_PASS' => '',
    'DB_NAME' => '',
];
PHP

cat > "$DEPLOY_DIR/UPLOAD_README.txt" <<'TXT'
Cyberduck upload steps

1. Upload the contents of deploy/public_html/ directly into the teacher server's public_html/ directory.
2. Do not create a nested public_html/public_html/ directory.
3. On the server, copy config.server.example.php to config.php.
4. Edit config.php on the server and set the teacher server DB settings and Gemini API key.
5. Do not publish config.php, upload it to GitHub, or share it publicly.
6. Do not place schema.sql inside the web-public directory. Apply schema.sql separately to PostgreSQL.

Important files after upload:
- public_html/index.php should exist on the server.
- public_html/includes/app_config.php should exist on the server.
- public_html/config.php must be created on the server from config.server.example.php.
TXT

find "$DEPLOY_PUBLIC" -name '*.php' -print0 | xargs -0 -n1 php -l

echo "Deploy folder generated: $DEPLOY_PUBLIC"

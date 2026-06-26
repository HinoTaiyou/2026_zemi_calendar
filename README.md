# PHP版 カレンダーAIアプリ

PHP + PostgreSQL で動く簡易カレンダーAIアプリです。月間表示、日別表示、予定CRUD、AIチャット、プランA/B/C、選択したAI提案のカレンダー登録を提供します。

参照仕様書は FastAPI / React 版の大規模仕様ですが、このリポジトリでは既存の PHP 構成を維持します。

## 必要なもの

- PHP 8.2 以上
- PostgreSQL
- PHP PostgreSQL 拡張
  - `pgsql` が必要です
  - `php -m | grep pgsql` で確認できます
- Gemini API キー
  - 任意です。未設定でもデモモードで動きます
  - 推奨環境変数名は `GEMINI_API_KEY` です

## セットアップ

詳しいローカルセットアップ、トラブルシューティング、セキュリティ確認は [docs/local-setup.md](docs/local-setup.md) を参照してください。

1. PostgreSQL にDBを作成します。

PostgreSQLの管理ロールや認証方式は環境によって異なります。必要に応じて、自分の環境でDB作成権限を持つロールから実行してください。

```sh
createdb -O "<DB_USER>" "<DB_NAME>"
```

2. テーブルを作成します。

```sh
psql -U "<DB_USER>" -h localhost -p 5432 -d "<DB_NAME>" -f schema.sql
```

3. 設定ファイルを作成します。

```sh
cp public_html/config.example.php public_html/config.php
```

4. `public_html/config.php` を編集します。

```php
return [
    'GEMINI_API_KEY' => '<GEMINI_API_KEY>',
    'GEMINI_MODEL' => 'gemini-3.1-flash-lite',
    'DB_HOST' => 'localhost',
    'DB_PORT' => '5432',
    'DB_USER' => '<DB_USER>',
    'DB_PASS' => '<DB_PASSWORD>',
    'DB_NAME' => '<DB_NAME>',
];
```

`GEMINI_API_KEY` を空文字のままにすると、AIチャットはデモモードで動きます。
サーバー環境変数 `GEMINI_API_KEY` が設定されている場合は、`config.php` の値より環境変数が優先されます。
最初に実APIで確認するモデル例は `gemini-3.1-flash-lite` です。`GEMINI_MODEL` を変更すれば別モデルも試せます。

環境変数で起動する例:

```sh
GEMINI_API_KEY="<GEMINI_API_KEY>" \
GEMINI_MODEL="gemini-3.1-flash-lite" \
DB_HOST="localhost" \
DB_PORT="5432" \
DB_USER="<DB_USER>" \
DB_PASS="<DB_PASSWORD>" \
DB_NAME="<DB_NAME>" \
php -S localhost:8000 -t public_html
```

実際のAPIキーをREADME、HTML、JavaScript、Git管理対象ファイルへ書かないでください。

5. ローカルサーバーを起動します。

```sh
php -S localhost:8000 -t public_html
```

6. ブラウザで開きます。

```text
http://localhost:8000
```

## 既存DBを更新する場合

過去版の `events` テーブルには `ai_idempotency_key` が無い場合があります。既存データを残したまま、次を実行してください。

```sql
ALTER TABLE events
  ADD COLUMN IF NOT EXISTS ai_idempotency_key VARCHAR(64) NULL;

CREATE INDEX IF NOT EXISTS idx_events_date_time
  ON events (event_date, event_time);

CREATE UNIQUE INDEX IF NOT EXISTS idx_events_ai_idempotency_key
  ON events (ai_idempotency_key)
  WHERE ai_idempotency_key IS NOT NULL;
```

アプリ起動時にも不足している列とindexは自動確認します。

## 動作確認

- 月間表示: `/index.php`
- 日別表示: カレンダーの日付をクリック
- 手動追加: 日別画面の「予定を追加」
- 編集: 日別画面の「編集」
- 削除: 日別画面の「削除」
- AIチャット: 月間画面の「AIチャット」
- AI登録: AIが出したプランを選び、「この内容でカレンダーに追加」

衝突する予定を登録しようとすると、既存予定が表示されます。「それでも登録する」を押した場合だけ登録できます。

## テスト

PHP構文チェック:

```sh
find public_html -name '*.php' -exec php -l {} +
```

軽量テスト:

```sh
php tests/run.php
```

実DB設定がある場合は、ブラウザから手動追加、編集、削除、AI提案登録、AI提案の連打、衝突警告、衝突許可登録を確認してください。

## 秘密情報

`public_html/config.php`、`.env`、APIキー、DBパスワードはGitへ追加しないでください。設定例は `public_html/config.example.php` に置いています。
Gemini APIキーはPHPサーバー側だけで管理し、フロントエンドへ埋め込まないでください。
環境変数が無い場合のみ、Git管理対象外の `public_html/config.php` にある同名設定を使用します。
Gemini は `GEMINI_API_KEY` がどちらにも無い場合だけデモモードになります。

## 実装メモ

- POST処理にはCSRFトークンを付けています。
- 日付・時刻・タイトル・所要時間はサーバー側で検証します。
- AI提案には `ai_idempotency_key` を付与し、同じ提案の再送で重複登録されないようにしています。
- 手動予定は `ai_idempotency_key = NULL` のため、同じ内容を意図的に別件として登録できます。
- 時間衝突は半開区間 `new_start < existing_end AND new_end > existing_start` と同じ条件で判定します。

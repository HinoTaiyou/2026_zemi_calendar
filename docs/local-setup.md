# Local setup with Gemini API and PostgreSQL

この手順は、ローカル環境で実際のGemini APIとPostgreSQLを使ってカレンダーAIアプリを動かすためのものです。実際のAPIキーやDBパスワードは、このファイル、README、Git管理対象ファイル、チャット、Issue、Pull Requestには書かないでください。

## 前提

- PHP 8.2以上
- PHP拡張 `curl`
- PHP拡張 `pgsql`
- PostgreSQL
- Gemini APIキー

確認コマンド:

```sh
php -v
php -m | grep curl
php -m | grep pgsql
```

## 設定の優先順位

アプリは次の順に設定値を読みます。

1. 環境変数
2. `public_html/config.php`
3. 安全なデフォルト値

対象の設定名:

- `GEMINI_API_KEY`
- `GEMINI_MODEL`
- `DB_HOST`
- `DB_PORT`
- `DB_USER`
- `DB_PASS`
- `DB_NAME`

`GEMINI_API_KEY` が未設定または空文字の場合、AIチャットはデモモードになります。最初の実API確認では `GEMINI_MODEL` に `gemini-3.1-flash-lite` を指定してください。モデル名は設定で変更できます。

## PostgreSQLセットアップ

macOSでHomebrew版PostgreSQLを使う場合の例です。PostgreSQLの管理ロールや認証方式は環境によって異なるため、必要に応じて自分の環境でDB作成権限を持つロールから実行してください。特定の管理ユーザー名は前提にしていません。

PostgreSQLの起動確認:

```sh
brew services list
pg_isready
```

起動していない場合:

```sh
brew services start postgresql
```

ロール作成。パスワードを履歴に残しにくいので、まずは対話式を推奨します。

```sh
createuser --pwprompt "<DB_USER>"
```

既存ロールにパスワードを設定する場合の例:

```sh
psql postgres -c "ALTER ROLE \"<DB_USER>\" WITH PASSWORD '<DB_PASSWORD>';"
```

DB作成:

```sh
createdb -O "<DB_USER>" "<DB_NAME>"
```

スキーマ適用:

```sh
psql -U "<DB_USER>" -h localhost -p 5432 -d "<DB_NAME>" -f schema.sql
```

テーブル作成確認:

```sh
psql -U "<DB_USER>" -h localhost -p 5432 -d "<DB_NAME>" -c '\dt'
psql -U "<DB_USER>" -h localhost -p 5432 -d "<DB_NAME>" -c '\d events'
```

接続確認:

```sh
PGPASSWORD="<DB_PASSWORD>" psql -h localhost -p 5432 -U "<DB_USER>" -d "<DB_NAME>" -c 'SELECT 1;'
```

`PGPASSWORD=...` をコマンドに直接書くとシェル履歴に残ることがあります。気になる場合は一時的に `export PGPASSWORD` する、対話入力にする、履歴を無効化するなど、自分の環境に合う方法を使ってください。

## 方式A: config.phpで起動する

設定ファイルをコピーします。

```sh
cp public_html/config.example.php public_html/config.php
```

`public_html/config.php` をローカル値に編集します。コピー直後の `GEMINI_API_KEY`、`DB_USER`、`DB_PASS`、`DB_NAME` は空なので、実APIとDBを使う場合だけ自分の値に置き換えてください。

安全な形の例:

```php
<?php
declare(strict_types=1);

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

`public_html/config.php` はGit管理対象外です。Gitリポジトリとして管理している環境では次を確認してください。

```sh
git check-ignore public_html/config.php
git status --short
```

`git check-ignore` が `public_html/config.php` を表示し、`git status --short` に `public_html/config.php` が出ない状態が安全です。

起動:

```sh
php -S localhost:8000 -t public_html
```

## 方式B: 環境変数だけで起動する

`public_html/config.php` がなくても、環境変数を渡せば起動できます。

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

このアプリは `.env` を自動読み込みしません。`.env` を使う場合は、別途シェルで読み込むなどの操作が必要です。`.env` 自動読み込みライブラリは導入していません。

環境変数をコマンドに直接書くとシェル履歴に秘密情報が残る場合があります。共有端末や録画中の画面では特に注意してください。

## ブラウザでの動作確認

ブラウザで開きます。

```text
http://localhost:8000
```

チェックリスト:

- PHPサーバーが起動する
- トップページが表示される
- 月間カレンダーが表示される
- DB接続エラーがない
- 手動で予定を追加できる
- 予定を編集できる
- 予定を削除できる
- AIチャット画面に「デモモードです」が表示されない
- AIチャットがGemini APIの応答を返す
- AIからプランA/B/Cが返る
- プランを選択できる
- 選択した提案をカレンダーへ登録できる
- 登録した予定がカレンダーに表示される
- 同じAI提案を再登録しても重複登録されない
- 衝突する時間の予定を登録した場合に警告が出る
- APIキーが画面やログに表示されない
- DBパスワードが画面やログに表示されない

実API利用中かどうかは、AIチャット画面のデモモード表示が消えることと、`GEMINI_API_KEY` が設定済みであることから判断してください。APIキーそのものを画面やログに表示して確認してはいけません。

## トラブルシューティング

PHPサーバーが起動しない:

```sh
php -v
php -S localhost:8000 -t public_html
```

別プロセスが8000番を使っている場合は、`localhost:8001` など別ポートを使ってください。

`curl`拡張がない:

```sh
php -m | grep curl
```

見つからない場合は、使っているPHPに `curl` 拡張を入れてください。

`pgsql`または`pdo_pgsql`拡張がない:

```sh
php -m | grep pgsql
```

このアプリのDB接続は `pg_connect` を使うため、少なくとも `pgsql` が必要です。

PostgreSQLが起動していない:

```sh
pg_isready
brew services list
brew services start postgresql
```

DB接続拒否:

- `DB_HOST` と `DB_PORT` を確認する
- PostgreSQLが起動しているか確認する
- `pg_isready -h localhost -p 5432` を試す

DBユーザーまたはパスワードの誤り:

```sh
PGPASSWORD="<DB_PASSWORD>" psql -h localhost -p 5432 -U "<DB_USER>" -d "<DB_NAME>" -c 'SELECT 1;'
```

DB名の誤り:

```sh
psql -l
```

schema.sql未適用、またはテーブルが存在しない:

```sh
psql -U "<DB_USER>" -h localhost -p 5432 -d "<DB_NAME>" -f schema.sql
psql -U "<DB_USER>" -h localhost -p 5432 -d "<DB_NAME>" -c '\dt'
psql -U "<DB_USER>" -h localhost -p 5432 -d "<DB_NAME>" -c '\d events'
```

`config.php` が読み込まれていない:

- ファイル名が `public_html/config.php` か確認する
- `public_html/config.example.php` のままでは読み込まれません
- PHP配列の `return [...]` 形式または既存互換の `define(...)` 形式になっているか確認する

環境変数がPHPプロセスに渡っていない:

環境変数は `php -S ...` を起動した同じシェルで渡してください。別ターミナルで設定した値は、起動済みプロセスには反映されません。

`GEMINI_API_KEY`未設定:

AIチャットはデモモードになります。実APIを使う場合は `public_html/config.php` か環境変数に設定してください。

Gemini HTTP 400:

- リクエスト形式やモデルの対応機能を確認する
- モデル名に余分な空白がないか確認する

Gemini HTTP 401または403:

- APIキーの誤り、失効、権限不足を確認する
- APIキーを再発行した場合はPHPサーバーを再起動する

Gemini HTTP 429:

- レート制限またはクォータ関連です
- しばらく待って再試行する
- Google AI Studio側の利用枠を確認する

Gemini HTTP 503:

- モデルまたはサービスの一時的な利用不可です
- `gemini-3.5-flash` で503になる場合、初期確認として `GEMINI_MODEL` を `gemini-3.1-flash-lite` に切り替えてください
- 429とは原因が違うため、クォータだけで判断しないでください

GeminiレスポンスのJSON解析失敗:

- アプリは一度だけJSON修復プロンプトを試します
- それでも失敗する場合は、AIが予定JSONを返せるように条件を具体化してください

AIチャットは成功するがカレンダー登録だけ失敗する:

- DB接続設定を確認する
- `events` テーブルがあるか確認する
- 登録しようとした予定の日時形式、時間衝突を確認する

CSRFエラー:

- ページを再読み込みして再度送信する
- 複数タブで古いフォームを送っていないか確認する

AI提案の重複登録:

- AI提案には `ai_idempotency_key` が付与されます
- 同じ提案の再送は重複登録されず、登録済みとして扱われます

予定の時間衝突:

- 既存予定と時間が重なる場合は警告が出ます
- 必要な場合だけ「それでも登録する」を選んでください

PHPエラーログ確認:

```sh
php -i | grep error_log
```

PHP内蔵サーバーで起動している場合は、サーバーを起動したターミナルにもエラーが表示されます。ログを共有するときはAPIキー、DBパスワード、接続文字列が含まれていないことを確認してください。

## セキュリティ注意点

- APIキーをGitへコミットしない
- DBパスワードをGitへコミットしない
- `public_html/config.php` をチャット、Issue、Pull Requestへ貼らない
- スクリーンショットに秘密情報を写さない
- シェル履歴に秘密情報が残る可能性がある
- `phpinfo()` を公開状態で設置しない
- エラー画面に接続文字列やパスワードを表示しない
- APIキーをJavaScriptへ埋め込まない
- Gemini APIはPHPサーバー側から呼び出す
- 誤ってコミットした場合は、ファイルを削除するだけでなくキーとパスワードを失効・再発行する
- Git履歴に残った秘密情報は通常の削除だけでは消えない

## 検証コマンド

```sh
php tests/run.php
find public_html -name '*.php' -exec php -l {} +
git diff --check
git status --short
```

この作業環境に実際のPostgreSQL設定やGemini APIキーがない場合、DB接続成功や実API応答成功までは確認できません。その場合は、上の手順を使って自分のローカル値で確認してください。

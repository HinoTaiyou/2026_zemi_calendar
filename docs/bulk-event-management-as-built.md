# Bulk Event Management — As-Built

A guarded screen (`public_html/event_manage.php`, nav link **予定を整理**) to delete many
events at once by period / weekday / title keyword / registration source / study-plan batch,
plus the metadata that lets AI study plans be managed as a unit. Designed so a user's real
events are never destroyed: every delete is previewed, server-re-derived, transactional,
capped, and CSRF-protected. There is no "delete everything".

## Database (additive migration)

`migrations/0001_event_source_metadata.sql` (and the same idempotent statements in
`schema.sql`) add three **nullable** columns to `events`: `source_type VARCHAR(32)`,
`source_batch_id VARCHAR(64)`, `source_label VARCHAR(255)`, plus indexes on
`source_batch_id` and `source_type`. Additive only — no drops/type changes; existing rows
keep NULL (treated as "登録元不明"). Re-running is safe (`ADD COLUMN IF NOT EXISTS`). **Not
auto-applied to prod**; apply with the dedicated DB, e.g. `psql … -f migrations/0001_…sql`
or by running `schema.sql`.

## How events are tagged

- Manual events (`createEvent` in `events.php`) → `source_type='manual'`.
- Study-plan registration (`chat.php` confirm) → `source_type='study_plan'`, a deterministic
  `source_batch_id` = `studyBatchId(qualification, planId)` (`study_planner.php`), and
  `source_label` = qualification ・ plan name. `insertEventRow` writes all three;
  `mapEventRow` reads them; `validateEventPayload` whitelists them (type enum, batch-id
  pattern `^[A-Za-z0-9_-]{1,64}$`, label ≤255). Existing/legacy rows stay NULL.

## Filter (pure, in `includes/event_admin.php`)

`normalizeBulkFilter()` validates: `start_date`/`end_date` (strict Y-m-d, **inclusive**),
`weekdays` (subset of ISO 1–7, deduped/sorted), `keyword` (≤100, trimmed), `source`
(`any|manual|study_plan|other_ai|unknown`), `batch_id`, `future_only`. Conditions are AND-ed.
`buildBulkFilterWhere()` returns a parameterized WHERE: dates/keyword/source/batch use
placeholders; weekdays embed validated integer literals via `EXTRACT(ISODOW …)::int IN (…)`;
keyword uses `title ILIKE … ESCAPE '\'` with `%`/`_`/`\` escaped (`escapeLikeTerm`);
`source=unknown` → `source_type IS NULL`. An empty filter yields `1=0` (matches nothing) and
is rejected upstream — no accidental mass delete.

## Preview & confirmation

`POST preview` computes `previewBulkEvents()` (count, first/last date, total minutes, by
source, by weekday, ≤50 rows) and stores `{filter, fingerprint, count, requires_strong}` in
the session. The delete form carries the fingerprint. `POST delete` requires: valid CSRF; a
matching session preview (same `bulkFilterFingerprint`); the recomputed count unchanged (else
re-preview); and, when `bulkFilterRequiresStrongConfirm` (≥50 rows, or an unbounded range with
no specific batch), the user must type `削除`. The delete **re-derives ids from the filter**
(`DELETE … WHERE id IN (SELECT id … WHERE <filter>)`) inside `BEGIN/COMMIT` (rollback on
error — never partial) and refuses if count > `BULK_DELETE_MAX` (500).

## CSV export

`POST csv` streams the filtered rows as UTF-8 (BOM) with `Content-Disposition`. `csvCell()`
applies RFC-4180 quoting and neutralizes formula injection (prefixing `'` before `= + - @`).
Columns: id, date, time, duration_minutes, title, source_type, source_label, source_batch_id.
No secrets. CSV is optional and never required for deletion.

## Quick links

Header **予定を整理** on all main pages. After a study registration, `index.php` shows
**この学習プランの予定を管理** → `event_manage.php?batch=<id>`. `event_edit.php` shows
**同じ学習プランの予定をまとめて管理** when the event has a `source_batch_id`. `day.php`
shows a discreet `source_label` chip on study-plan events (no raw id).

## Caps & limits

`BULK_DELETE_MAX = 500`, `BULK_PREVIEW_ROWS = 50`, `BULK_STRONG_CONFIRM_THRESHOLD = 50`,
`BULK_KEYWORD_MAX_LENGTH = 100`.

## Tests

`tests/run.php` adds 47 (160 total) covering filter normalization, inclusive dates, weekday
subset/validation, ILIKE wildcard escaping, source enum + `unknown`→IS NULL, AND composition,
empty rejection, fingerprint stability/change, strong-confirm thresholds, the 500 cap, CSV
escaping/injection, and source-metadata validation + batch ids — all pure (no DB). The DB
delete path (transaction, CSRF, fingerprint, strong-confirm) was verified live against
`[BULK-DELETE-TEST]`-tagged data: 6 tagged events deleted, all real events untouched.

## Manual verification still recommended

Browser run across 1440/1024/768/390; preview→CSV→delete with your own tagged data; the
post-register and edit-page batch links; JavaScript console. Migration application to any
non-dev database.

## Extending later

`source_type='other_ai'` is reserved for future non-study AI sources. A future per-user scope
can be added as another AND clause in `buildBulkFilterWhere()` without touching callers.

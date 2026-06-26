-- Migration 0001: event source metadata (additive, idempotent).
--
-- Adds nullable columns so events can record where they came from and which
-- study-plan registration batch they belong to. Safe to run multiple times;
-- existing rows keep NULL (treated as "登録元不明"). No drops or type changes.
--
-- Apply with the dedicated app database, e.g.:
--   PGPASSWORD=... psql -h localhost -p 5433 -U <user> -d <db> -f migrations/0001_event_source_metadata.sql
-- (or run schema.sql, which now contains the same idempotent statements).

ALTER TABLE events
  ADD COLUMN IF NOT EXISTS source_type VARCHAR(32) NULL;
ALTER TABLE events
  ADD COLUMN IF NOT EXISTS source_batch_id VARCHAR(64) NULL;
ALTER TABLE events
  ADD COLUMN IF NOT EXISTS source_label VARCHAR(255) NULL;

CREATE INDEX IF NOT EXISTS idx_events_source_batch_id ON events (source_batch_id);
CREATE INDEX IF NOT EXISTS idx_events_source_type ON events (source_type);

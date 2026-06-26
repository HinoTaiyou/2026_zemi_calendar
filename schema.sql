CREATE TABLE IF NOT EXISTS events (
  id SERIAL PRIMARY KEY,
  event_date DATE NOT NULL,
  event_time TIME NOT NULL,
  duration_minutes INT NOT NULL DEFAULT 30,
  title VARCHAR(255) NOT NULL,
  ai_idempotency_key VARCHAR(64) NULL,
  source_type VARCHAR(32) NULL,
  source_batch_id VARCHAR(64) NULL,
  source_label VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_events_date ON events (event_date);
-- Existing database upgrade path. Safe to run more than once.
ALTER TABLE events
  ADD COLUMN IF NOT EXISTS ai_idempotency_key VARCHAR(64) NULL;
ALTER TABLE events
  ADD COLUMN IF NOT EXISTS source_type VARCHAR(32) NULL;
ALTER TABLE events
  ADD COLUMN IF NOT EXISTS source_batch_id VARCHAR(64) NULL;
ALTER TABLE events
  ADD COLUMN IF NOT EXISTS source_label VARCHAR(255) NULL;
CREATE INDEX IF NOT EXISTS idx_events_date_time ON events (event_date, event_time);
CREATE UNIQUE INDEX IF NOT EXISTS idx_events_ai_idempotency_key
  ON events (ai_idempotency_key)
  WHERE ai_idempotency_key IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_events_source_batch_id ON events (source_batch_id);
CREATE INDEX IF NOT EXISTS idx_events_source_type ON events (source_type);

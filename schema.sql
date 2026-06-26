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
ALTER TABLE events
  ADD COLUMN IF NOT EXISTS adopted_plan_id INT NULL;

CREATE TABLE IF NOT EXISTS adopted_plans (
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
);

CREATE INDEX IF NOT EXISTS idx_adopted_plans_status_follow_up
  ON adopted_plans (status, follow_up_due_at);

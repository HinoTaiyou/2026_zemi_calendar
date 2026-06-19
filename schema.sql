CREATE TABLE IF NOT EXISTS events (
  id SERIAL PRIMARY KEY,
  event_date DATE NOT NULL,
  event_time TIME NOT NULL,
  duration_minutes INT NOT NULL DEFAULT 30,
  title VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_events_date ON events (event_date);

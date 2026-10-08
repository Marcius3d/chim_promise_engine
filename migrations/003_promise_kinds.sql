-- CHIM - Promise Engine 0.1: promise kinds (place, kill, free), unique promises and why a promise closed.
ALTER TABLE plugins.chim_promise_promises ADD COLUMN IF NOT EXISTS kind TEXT NOT NULL DEFAULT 'free';
ALTER TABLE plugins.chim_promise_promises ADD COLUMN IF NOT EXISTS target TEXT NOT NULL DEFAULT '';
ALTER TABLE plugins.chim_promise_promises ADD COLUMN IF NOT EXISTS is_unique BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE plugins.chim_promise_promises ADD COLUMN IF NOT EXISTS subject TEXT NOT NULL DEFAULT '';
ALTER TABLE plugins.chim_promise_promises ADD COLUMN IF NOT EXISTS close_reason TEXT NOT NULL DEFAULT '';
CREATE INDEX IF NOT EXISTS idx_chim_promise_promises_subject ON plugins.chim_promise_promises (playthrough_id, subject);

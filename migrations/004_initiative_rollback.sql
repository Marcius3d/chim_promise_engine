-- CHIM - Promise Engine 0.1: after a save load, initiatives are hidden instead of deleted,
-- so turn-taking between followers stays fair (the cooldown still counts them).
ALTER TABLE plugins.chim_promise_initiatives ADD COLUMN IF NOT EXISTS rolled_back BOOLEAN NOT NULL DEFAULT FALSE;

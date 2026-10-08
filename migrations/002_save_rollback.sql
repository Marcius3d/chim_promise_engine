-- CHIM - Promise Engine 0.1: follow CHIM when an older save is loaded (or the player dies).
ALTER TABLE plugins.chim_promise_initiatives ADD COLUMN IF NOT EXISTS created_gamets BIGINT NOT NULL DEFAULT 0;

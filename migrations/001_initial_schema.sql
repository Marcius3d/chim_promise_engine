-- CHIM - Promise Engine 0.1: settings, promises, initiatives and diagnostics.
-- Plugin tables live outside CHIM playthrough saves, so every row carries the playthrough it belongs to.
CREATE SCHEMA IF NOT EXISTS plugins;

CREATE TABLE IF NOT EXISTS plugins.chim_promise_settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL DEFAULT '',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- status: offered (waiting for the player's answer), open, kept, declined, expired, cancelled
CREATE TABLE IF NOT EXISTS plugins.chim_promise_promises (
    id BIGSERIAL PRIMARY KEY,
    playthrough_id INTEGER NOT NULL DEFAULT 0,
    playthrough_name TEXT NOT NULL DEFAULT '',
    npc TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'offered',
    lens TEXT NOT NULL DEFAULT '',
    title TEXT NOT NULL DEFAULT '',
    place TEXT NOT NULL DEFAULT '',
    offer_text TEXT NOT NULL DEFAULT '',
    player_answer TEXT NOT NULL DEFAULT '',
    reaction_text TEXT NOT NULL DEFAULT '',
    tries INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_gamets BIGINT NOT NULL DEFAULT 0,
    agreed_at TIMESTAMP NULL,
    agreed_gamets BIGINT NOT NULL DEFAULT 0,
    resolved_at TIMESTAMP NULL,
    resolved_gamets BIGINT NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_chim_promise_promises_pt ON plugins.chim_promise_promises (playthrough_id, status, npc);

CREATE TABLE IF NOT EXISTS plugins.chim_promise_initiatives (
    id BIGSERIAL PRIMARY KEY,
    playthrough_id INTEGER NOT NULL DEFAULT 0,
    npc TEXT NOT NULL,
    lens TEXT NOT NULL DEFAULT '',
    spoken TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_ts BIGINT NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_chim_promise_initiatives_npc ON plugins.chim_promise_initiatives (playthrough_id, npc, id);

CREATE TABLE IF NOT EXISTS plugins.chim_promise_log (
    id BIGSERIAL PRIMARY KEY,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    level TEXT NOT NULL DEFAULT 'info',
    message TEXT NOT NULL DEFAULT ''
);

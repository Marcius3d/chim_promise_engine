# CHIM - Promise Engine: agent notes

Server-only CHIM add-on (PHP). Installs into `HerikaServer/ext/chim_promise_engine/`.

## Contracts

- **Hooks.**
  - `preprocessing.php` (main.php, before CHIM routes the request):
    - `bored`: `cpeHandleBored()` may return a plan. The request then becomes a CHIM `suggestion` for the follower (`$_GET['profile']` = the follower's `core_npc_master.md5`, `$gameRequest[3]` = `"The Narrator: (...)"`; CHIM strips the leading `Name:` of suggestions).
    - Player input types: the raw line is kept in `$GLOBALS['CPE_PLAYER_TEXT']`.
  - `init` / `playerdied`: a shutdown function runs `cpeRollback()` after CHIM's `processor/comm.php` pruned its own history (same game time: the loaded `gamets`, or the last `infosave` on death). Skipped when CHIM sets `pgr_skip_rollback`. Promises made later are deleted, kept later are reopened.
  - `postrequest.php` (end of main.php, after the reply was sent): stores what the follower said (`$GLOBALS['talkedSoFar']`), or checks a pending offer against the player's line.
  - Never give files in `lib/` a hook name; `scripts/build_packages.py` enforces this.
- **Agreement check.** One `fast_request` on the current NPC's connector (`CHIM_CORE_CURRENT_CONNECTOR_DATA`), JSON `{decision,title,kind,target,place,unique}`. Keyword fallback if no JSON comes back.
- **Deaths.** CHIM `death` rows in `eventlog` ("A killed B", "B died", "B was killed by A"), parsed by `cpeDeathParse()`. Kill promises resolve from them; a follower's own death closes their promises. Unique promises are matched by `subject` (kind + normalised target/place/title).
- **Prompt size.** The initiative adds the lens text plus at most `CPE_AVOID_MAX` (8) "not this" entries; sizes are estimated in `tokens_last_initiative` / `tokens_last_check`.
- **Notifications.** A `responselog` row with `actor=rolemaster`, `action=rolecommand|DebugNotification@<text>` (same as CHIM core). `$db->insert()` fills `interaction_generation`.
- **Playthroughs.** Plugin tables are outside CHIM Playthrough Saves, so rows carry `playthrough_id` from `chim_meta.playthrough_profiles` (active row), unless `playthrough_override` is set on the plugin page. No `chim_meta`: id 0 "Default".
- **Database.** `plugins.chim_promise_settings` (key/value), `plugins.chim_promise_promises` (status: offered, open, kept, declined, expired, cancelled, impossible, lapsed), `plugins.chim_promise_initiatives`, `plugins.chim_promise_log` (trimmed to 2000). Migrations are append-only once released.

## Versioning

Bump the same version in `manifest.json`, `dwemer-package.json` and `lib/promise_engine.php`, and add a `CHANGELOG.md` entry. `python3 scripts/build_packages.py --check-only` verifies they match.

## Validation

- `php -l` on changed PHP files.
- `php tests/server_policy_test.php`. With `CPE_TEST_PG="host=... dbname=..."` it also runs the database flow against a scratch PostgreSQL (drops and recreates its tables there).
- Report in-game testing separately; passing checks do not prove a follower speaks in Skyrim.

## Attribution

Commits and releases are by Marcius3d. Mention the helper only as "And Santa's little helper Claude". No co-author lines or session links.

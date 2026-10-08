<?php
/**
 * CHIM - Promise Engine: shared server helpers.
 *
 * Note: HerikaServer loads hook files (preprocessing.php, postrequest.php, ...) recursively from
 * every folder under ext/. Never give files in lib/ a hook name.
 */

if (defined('CPE_VERSION')) {
    return;
}

define('CPE_VERSION', '0.5.0');
define('CPE_NAME', 'CHIM - Promise Engine');
define('CPE_SETTINGS', 'plugins.chim_promise_settings');
define('CPE_PROMISES', 'plugins.chim_promise_promises');
define('CPE_INITIATIVES', 'plugins.chim_promise_initiatives');
define('CPE_LOG', 'plugins.chim_promise_log');
define('CPE_LOG_KEEP', 2000);
define('CPE_LENSES', ['aspiration', 'activity', 'grudge', 'relationship', 'curiosity']);
/** Lenses that end with a proposal the player can agree to. */
define('CPE_OFFER_LENSES', ['aspiration', 'activity', 'grudge']);
define('CPE_KINDS', ['place', 'kill', 'free']);
/** CHIM game time: 1 game hour = 1 / 0.0000024 units. */
define('CPE_GAMETS_PER_DAY', 10000000);
/** Most entries in the "do not propose" list sent with a proposal (keeps the prompt small). */
define('CPE_AVOID_MAX', 8);
define('CPE_PLAYER_INPUT_TYPES', ['inputtext', 'inputtext_s', 'ginputtext', 'ginputtext_s']);

function cpeDefaults(): array
{
    return [
        'enabled' => '1',
        'initiative_chance' => '30',        // % of CHIM "bored" moments a follower uses for an initiative
        'global_cooldown_minutes' => '6',   // real minutes between two initiatives (any follower)
        'npc_cooldown_minutes' => '20',     // real minutes before the same follower takes the initiative again
        'quiet_after_combat_seconds' => '90',
        // How often each lens is picked: share = weight / sum of weights (0 = never)
        'weight_aspiration' => '30',
        'weight_activity' => '25',
        'weight_grudge' => '10',
        'weight_relationship' => '20',
        'weight_curiosity' => '15',
        'offer_expiry_minutes' => '5',      // how long a proposal waits for the player's answer
        'max_open_per_npc' => '3',          // more open promises than this: the follower only talks, no new proposals
        'promise_expiry_days' => '30',      // open promises older than this (game days) are quietly forgotten; 0 = never
        'notify_remember' => '1',
        'notify_kept' => '1',
        'playthrough_override' => '',       // '' = follow CHIM's active playthrough
        'debug' => '0',
        // State and counters
        'last_initiative_ts' => '0',
        'last_location_seen' => '',
        'stat_initiatives' => '0',
        'stat_offers' => '0',
        'stat_agreed' => '0',
        'stat_declined' => '0',
        'stat_kept' => '0',
        'stat_moot' => '0',
        'tokens_last_initiative' => '0',    // rough size of the text Promise Engine added to the last initiative
        'tokens_last_check' => '0',         // rough size of the last agreement check (sent)
    ];
}

// ---------------------------------------------------------------------------------------
// Database helpers
// ---------------------------------------------------------------------------------------

function cpeDb()
{
    return $GLOBALS['db'] ?? null;
}

/** Replace $1, $2 ... with safely quoted literals (HerikaServer's fetchAll takes no parameters). */
function cpeBind(string $sql, array $params): string
{
    $db = cpeDb();
    return preg_replace_callback('/\$(\d+)/', static function ($m) use ($params, $db) {
        $i = (int)$m[1] - 1;
        if (!array_key_exists($i, $params)) {
            return $m[0];
        }
        $v = $params[$i];
        if ($v === null) {
            return 'NULL';
        }
        if (is_int($v)) {
            return (string)$v;
        }
        return $db->escapeLiteral((string)$v);
    }, $sql);
}

function cpeAll(string $sql, array $params = []): array
{
    $db = cpeDb();
    if (!$db) {
        return [];
    }
    try {
        return (array)$db->fetchAll(cpeBind($sql, $params));
    } catch (Throwable $e) {
        cpeLog('error', 'SQL failed: ' . $e->getMessage());
        return [];
    }
}

function cpeOne(string $sql, array $params = []): array
{
    $db = cpeDb();
    if (!$db) {
        return [];
    }
    try {
        return (array)$db->fetchOne(cpeBind($sql, $params));
    } catch (Throwable $e) {
        return [];
    }
}

function cpeTableExists(string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    $db = cpeDb();
    if (!$db) {
        return false;
    }
    try {
        $row = $db->fetchOne('SELECT to_regclass($1) AS t', [$table]);
        return $cache[$table] = !empty($row['t']);
    } catch (Throwable $e) {
        return $cache[$table] = false;
    }
}

function cpeDbReady(): bool
{
    return cpeTableExists(CPE_SETTINGS) && cpeTableExists(CPE_PROMISES) && cpeTableExists(CPE_INITIATIVES);
}

function cpeSettings(bool $fresh = false): array
{
    static $cached = null;
    if ($cached !== null && !$fresh) {
        return $cached;
    }
    $settings = cpeDefaults();
    if (cpeTableExists(CPE_SETTINGS)) {
        foreach (cpeAll('SELECT key, value FROM ' . CPE_SETTINGS) as $row) {
            if (array_key_exists($row['key'] ?? '', $settings)) {
                $settings[$row['key']] = (string)$row['value'];
            }
        }
    }
    return $cached = $settings;
}

function cpeSet(string $key, string $value): bool
{
    if (!cpeTableExists(CPE_SETTINGS) || !array_key_exists($key, cpeDefaults())) {
        return false;
    }
    $row = cpeOne('INSERT INTO ' . CPE_SETTINGS . ' (key, value, updated_at) VALUES ($1, $2, CURRENT_TIMESTAMP) '
        . 'ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = CURRENT_TIMESTAMP RETURNING key', [$key, $value]);
    cpeSettings(true);
    return !empty($row);
}

function cpeIncrement(string $key): void
{
    if (!cpeTableExists(CPE_SETTINGS)) {
        return;
    }
    cpeOne('INSERT INTO ' . CPE_SETTINGS . " (key, value) VALUES ($1, '1') ON CONFLICT (key) DO UPDATE SET "
        . "value = (COALESCE(NULLIF(" . CPE_SETTINGS . ".value, ''), '0')::bigint + 1)::text, updated_at = CURRENT_TIMESTAMP RETURNING key", [$key]);
}

function cpeOn(array $settings, string $key): bool
{
    return ($settings[$key] ?? '0') === '1';
}

// ---------------------------------------------------------------------------------------
// Diagnostics log
// ---------------------------------------------------------------------------------------

function cpeLog(string $level, string $message): void
{
    if ($level === 'debug' && !cpeOn(cpeSettings(), 'debug')) {
        return;
    }
    if (class_exists('Logger') && method_exists('Logger', $level === 'debug' ? 'debug' : $level)) {
        $method = $level === 'debug' ? 'debug' : $level;
        Logger::$method('[promise_engine] ' . $message);
    }
    if (!cpeTableExists(CPE_LOG)) {
        return;
    }
    try {
        cpeDb()->fetchOne('INSERT INTO ' . CPE_LOG . ' (level, message) VALUES ($1, $2) RETURNING id',
            [substr($level, 0, 16), mb_substr($message, 0, 1000)]);
        if (random_int(1, 50) === 1) {
            cpeDb()->fetchOne('DELETE FROM ' . CPE_LOG . ' WHERE id <= (SELECT COALESCE(MAX(id), 0) - $1 FROM ' . CPE_LOG . ') RETURNING 1', [CPE_LOG_KEEP]);
        }
    } catch (Throwable $e) {
        // Logging must never break a game request.
    }
}

// ---------------------------------------------------------------------------------------
// Playthroughs (CHIM Playthrough Saves). Plugin tables are not part of a save, so every row is keyed.
// ---------------------------------------------------------------------------------------

/** All CHIM playthroughs: [id => ['id', 'name', 'active']]. */
function cpePlaythroughs(): array
{
    $list = [];
    if (cpeTableExists('chim_meta.playthrough_profiles')) {
        foreach (cpeAll('SELECT id, name, is_active FROM chim_meta.playthrough_profiles ORDER BY is_active DESC, created_at DESC, id DESC') as $row) {
            $active = in_array($row['is_active'] ?? '', ['t', true, '1', 1], true);
            $list[(int)$row['id']] = ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'active' => $active];
        }
    }
    if (cpeTableExists(CPE_PROMISES)) {
        foreach (cpeAll('SELECT playthrough_id, MAX(playthrough_name) AS name FROM ' . CPE_PROMISES . ' GROUP BY playthrough_id') as $row) {
            $id = (int)$row['playthrough_id'];
            if (!isset($list[$id])) {
                $list[$id] = ['id' => $id, 'name' => ($row['name'] ?? '') !== '' ? (string)$row['name'] : ($id === 0 ? 'Default' : "Playthrough $id"), 'active' => false];
            }
        }
    }
    if (!$list) {
        $list[0] = ['id' => 0, 'name' => 'Default', 'active' => true];
    }
    return $list;
}

/** The playthrough new promises belong to: CHIM's active one, unless overridden on the plugin page. */
function cpeCurrentPlaythrough(?array $settings = null): array
{
    $settings = $settings ?? cpeSettings();
    $all = cpePlaythroughs();
    $override = trim((string)($settings['playthrough_override'] ?? ''));
    if ($override !== '' && ctype_digit($override) && isset($all[(int)$override])) {
        return $all[(int)$override] + ['source' => 'manual'];
    }
    foreach ($all as $pt) {
        if ($pt['active']) {
            return $pt + ['source' => 'auto'];
        }
    }
    $first = reset($all);
    return $first + ['source' => 'auto'];
}

// ---------------------------------------------------------------------------------------
// Game state helpers
// ---------------------------------------------------------------------------------------

/** Names of the followers currently in the party. */
function cpeParty(): array
{
    $names = [];
    try {
        if (!function_exists('DataGetCurrentPartyConf')) {
            // Plugin page: CHIM's helper is not loaded; read the same conf_opts row it reads.
            $row = cpeOne("SELECT value FROM conf_opts WHERE id = 'CurrentParty'");
            $guys = json_decode('[' . rtrim(trim((string)($row['value'] ?? '')), ',') . ']', true);
            foreach (is_array($guys) ? $guys : [] as $guy) {
                if (!empty($guy['name']) && strcasecmp((string)$guy['name'], 'The Narrator') !== 0) {
                    $names[] = (string)$guy['name'];
                }
            }
            return $names;
        }
        if (function_exists('DataGetCurrentPartyConf')) {
            $party = json_decode((string)DataGetCurrentPartyConf(), true);
            if (is_array($party)) {
                foreach (array_keys($party) as $name) {
                    $name = trim((string)$name);
                    if ($name !== '' && strcasecmp($name, 'The Narrator') !== 0) {
                        $names[] = $name;
                    }
                }
            }
        }
    } catch (Throwable $e) {
        cpeLog('warn', 'Could not read the party: ' . $e->getMessage());
    }
    return $names;
}

function cpeNpcProfileHash(string $npc): string
{
    $row = cpeOne('SELECT md5 FROM core_npc_master WHERE npc_name = $1 LIMIT 1', [$npc]);
    return (string)($row['md5'] ?? '');
}

/** Current location name (as Skyrim names it), from the request or CHIM's last known location. */
function cpeCurrentLocation(string $requestText = ''): string
{
    if (preg_match('/Context\s*(?:new\s*)?location:\s*([^,\)]+)/u', $requestText, $m)) {
        return trim(preg_replace('/\s+(outdoors|interior)\s*$/iu', '', trim($m[1])));
    }
    if (function_exists('DataLastKnownLocationContextParts')) {
        $parts = DataLastKnownLocationContextParts();
        return (string)($parts['location_base'] ?? '');
    }
    return '';
}

/**
 * Every name in CHIM's location context, e.g. "(Context location: Vilemyr Inn, Ivarstead, Hold: The Rift)"
 * gives ["Vilemyr Inn", "Ivarstead", "The Rift"]. Falls back to CHIM's last known location.
 */
function cpeLocationParts(string $requestText = ''): array
{
    $raw = '';
    if (preg_match('/\(\s*Context\s*(?:new\s*)?location:\s*([^)]*)\)/iu', $requestText, $m)) {
        $raw = $m[1];
    } elseif (function_exists('DataLastKnownLocation')) {
        $last = (string)DataLastKnownLocation();
        if (preg_match('/\(\s*Context\s*(?:new\s*)?location:\s*([^)]*)\)/iu', $last, $m)) {
            $raw = $m[1];
        }
    }
    $raw = preg_split('/,\s*current date\b/iu', $raw)[0];   // time and weather are not places
    $parts = [];
    foreach (explode(',', $raw) as $part) {
        $part = trim(preg_replace('/^[A-Za-z ]{1,20}:\s*/u', '', trim($part)));   // "Hold: The Rift" -> "The Rift"
        $part = trim(preg_replace('/\s+(outdoors|interior)\s*$/iu', '', $part));
        if ($part !== '' && mb_strlen($part) <= 80 && !in_array($part, $parts, true)) {
            $parts[] = $part;
        }
    }
    return $parts;
}

function cpePlaceMatchesAny(string $place, array $parts): bool
{
    foreach ($parts as $part) {
        if (cpePlaceMatches($place, $part)) {
            return true;
        }
    }
    return false;
}

/**
 * Arrival check on every location update (preprocessing.php: location, infoloc, bored, player input).
 * A place promise is kept the moment the player is there with that follower in the party;
 * the follower reacts at the next quiet moment (close_reason 'react_pending').
 */
function cpeCheckArrival(array $gameRequest): int
{
    $settings = cpeSettings();
    if (!cpeOn($settings, 'enabled') || !cpeDbReady()) {
        return 0;
    }
    $pt = cpeCurrentPlaythrough($settings);
    $open = cpeAll('SELECT id, npc, title, place FROM ' . CPE_PROMISES . " WHERE playthrough_id = $1 AND status = 'open' AND kind <> 'kill' AND place <> '' ORDER BY id",
        [$pt['id']]);
    if (!$open) {
        return 0;
    }
    $parts = cpeLocationParts((string)($gameRequest[3] ?? ''));
    if (!$parts) {
        return 0;
    }
    $seen = implode(', ', $parts);
    if ($seen !== $settings['last_location_seen']) {
        cpeSet('last_location_seen', mb_substr($seen, 0, 300));
        cpeLog('info', 'Location: ' . $seen . ' (waiting for: ' . implode(', ', array_unique(array_column($open, 'place'))) . ')');
    }
    $party = cpeParty();
    $kept = 0;
    foreach ($open as $promise) {
        if (!in_array($promise['npc'], $party, true) || !cpePlaceMatchesAny($promise['place'], $parts)) {
            continue;
        }
        $changed = cpeOne('UPDATE ' . CPE_PROMISES . " SET status = 'kept', close_reason = 'react_pending', resolved_at = CURRENT_TIMESTAMP, resolved_gamets = $1 WHERE id = $2 AND status = 'open' RETURNING id",
            [(int)($gameRequest[2] ?? 0), (int)$promise['id']]);
        if (!$changed) {
            continue; // another request handled it at the same moment
        }
        cpeIncrement('stat_kept');
        cpeLog('info', "Promise kept: {$promise['npc']} - {$promise['title']} (at {$seen})");
        if (cpeOn($settings, 'notify_kept')) {
            cpeNotify('Promise kept: ' . $promise['title']);
        }
        $player = (string)($GLOBALS['PLAYER_NAME'] ?? 'the player');
        if (cpeQueueReaction($promise['npc'], cpeKeptText($promise['npc'], $player, $promise['title'], $promise['place']))) {
            cpeOne('UPDATE ' . CPE_PROMISES . " SET close_reason = '' WHERE id = $1 RETURNING id", [(int)$promise['id']]);
        }
        $kept++;
    }
    return $kept;
}

function cpeNormalizePlace(string $place): string
{
    $place = mb_strtolower(trim($place));
    $place = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $place);
    $place = preg_replace('/\b(the|of|in|at)\b/u', ' ', $place);
    return trim(preg_replace('/\s+/u', ' ', $place));
}

/** Does the current location fulfil a promise made for $place? */
function cpePlaceMatches(string $place, string $location): bool
{
    $p = cpeNormalizePlace($place);
    $l = cpeNormalizePlace($location);
    if ($p === '' || $l === '') {
        return false;
    }
    if ($p === $l) {
        return true;
    }
    // "Bee and Barb" matches the location "The Bee and Barb"; a whole word sequence must match.
    return (bool)preg_match('/(^| )' . preg_quote($p, '/') . '( |$)/u', $l);
}

/** Seconds since the last combat-related event, or null if none was seen. */
function cpeSecondsSinceCombat(): ?int
{
    $row = cpeOne("SELECT MAX(localts) AS t FROM eventlog WHERE type IN ('combatbark', 'combatend', 'combatendmighty', 'death')");
    $t = (int)($row['t'] ?? 0);
    return $t > 0 ? max(0, time() - $t) : null;
}

function cpeNotify(string $text): void
{
    $db = cpeDb();
    if (!$db) {
        return;
    }
    $text = trim(str_replace(['@', '|', "\r", "\n"], ' ', $text));
    try {
        $db->insert('responselog', [
            'localts' => time(),
            'sent' => 0,
            'actor' => 'rolemaster',
            'text' => '',
            'action' => 'rolecommand|DebugNotification@' . $text,
            'tag' => '',
        ]);
    } catch (Throwable $e) {
        cpeLog('warn', 'Could not queue the notification: ' . $e->getMessage());
    }
}

/**
 * Make a follower speak within seconds, the way CHIM's director does it: the game picks up a
 * "Suggestion" command from responselog and sends a suggestion request for that actor back to CHIM.
 */
function cpeQueueReaction(string $npc, string $text): bool
{
    $db = cpeDb();
    if (!$db || trim($npc) === '') {
        return false;
    }
    $text = preg_replace('/^The Narrator:\s*/', '', $text);
    // "@" and "|" separate fields; CHIM strips everything up to the first ":" of a suggestion.
    $text = trim(str_replace(['@', '|', ':', "\r", "\n"], [' ', ' ', ' -', ' ', ' '], $text));
    $npc = trim(str_replace(['@', '|'], ' ', $npc));
    try {
        $db->insert('responselog', [
            'localts' => time(),
            'sent' => 0,
            'actor' => 'rolemaster',
            'text' => '',
            'action' => 'rolecommand|Suggestion@' . $npc . '@' . $text . '@' . uniqid('cpe'),
            'tag' => '',
        ]);
        cpeSet('last_initiative_ts', (string)time());
        cpeLog('info', "Reaction queued: {$npc}");
        return true;
    } catch (Throwable $e) {
        cpeLog('warn', 'Could not queue the reaction: ' . $e->getMessage());
        return false;
    }
}

/** A "death" event arrived (preprocessing.php, after CHIM stored it): resolve kill promises right away. */
function cpeHandleDeathEvent(int $gamets): void
{
    $settings = cpeSettings();
    if (!cpeOn($settings, 'enabled') || !cpeDbReady()) {
        return;
    }
    $plan = cpeResolveDeaths(cpeCurrentPlaythrough($settings), cpeParty(), $gamets, true);
    if ($plan === null) {
        return;
    }
    cpeQueueReaction($plan['npc'], $plan['text']);
    if (($plan['notify'] ?? '') !== '' && cpeOn($settings, 'notify_kept')) {
        cpeNotify($plan['notify']);
    }
}

function cpeGameDate($gamets): string
{
    $gamets = (int)$gamets;
    if ($gamets <= 0) {
        return '';
    }
    if (!function_exists('convert_gamets2skyrim_long_date_no_time')) {
        $path = ($GLOBALS['ENGINE_PATH'] ?? dirname(__DIR__, 3) . DIRECTORY_SEPARATOR) . 'lib' . DIRECTORY_SEPARATOR . 'utils_game_timestamp.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
    return function_exists('convert_gamets2skyrim_long_date_no_time') ? (string)convert_gamets2skyrim_long_date_no_time($gamets) : '';
}

// ---------------------------------------------------------------------------------------
// Deaths (CHIM "death" events in eventlog, e.g. "Lydia killed Nazeem", "Nazeem died")
// ---------------------------------------------------------------------------------------

function cpeDeathRows(int $sinceGamets, int $limit = 300): array
{
    return cpeAll("SELECT data, gamets FROM eventlog WHERE type = 'death' AND gamets >= $1 ORDER BY gamets DESC LIMIT $2", [$sinceGamets, $limit]);
}

function cpeDeathText(string $data): string
{
    $data = preg_replace('/\((?:Context|context)[^)]*\)/u', '', $data);
    $data = preg_replace('/^The Narrator:\s*/iu', '', trim($data));
    return trim($data);
}

/** ['victim' => ..., 'killer' => ...] of a death line ('' when unknown). */
function cpeDeathParse(string $data): array
{
    $d = cpeDeathParseRaw($data);
    // "Sinding(powerful enemy) using weapon Steel Sword" -> "Sinding"
    $clean = static fn(string $n): string => trim(preg_replace(['/\s*\([^)]*\)/u', '/\s+using\s+.*$/iu'], '', $n));
    return ['victim' => $clean($d['victim']), 'killer' => $clean($d['killer'])];
}

function cpeDeathParseRaw(string $data): array
{
    $t = cpeDeathText($data);
    if (preg_match('/^(.+?)\s+(?:was killed by|was slain by)\s+(.+?)[.!]?$/iu', $t, $m)) {
        return ['victim' => trim($m[1]), 'killer' => trim($m[2])];
    }
    if (preg_match('/^(.+?)\s+(?:has defeated|defeated|has killed|killed|slew|has slain|slain)\s+(.+?)(?:\s+with\s+.+?)?(?:\s+in an awesome move)?[.!]?$/iu', $t, $m)) {
        return ['victim' => trim($m[2]), 'killer' => trim($m[1])];
    }
    if (preg_match('/^(.+?)\s+(?:was killed|was slain|has died|died|is dead)\b/iu', $t, $m)) {
        return ['victim' => trim($m[1]), 'killer' => ''];
    }
    return ['victim' => '', 'killer' => ''];
}

function cpeDeathVictim(string $data): string
{
    return cpeDeathParse($data)['victim'];
}

function cpeNameIn(string $name, string $text): bool
{
    $name = trim($name);
    return $name !== '' && (bool)preg_match('/(?<![\p{L}\p{N}])' . preg_quote($name, '/') . '(?![\p{L}\p{N}])/iu', $text);
}

/** Named people who died most recently (newest first, unique), for the "already dead" hint. */
function cpeRecentDead(int $limit): array
{
    $names = [];
    foreach (cpeDeathRows(0, 60) as $row) {
        $victim = cpeDeathVictim((string)$row['data']);
        if ($victim !== '' && !in_array($victim, $names, true)) {
            $names[] = $victim;
        }
        if (count($names) >= $limit) {
            return $names;
        }
    }
    // Plus NPCs CHIM's profiles mark as dead (newest updates first).
    foreach (cpeAll("SELECT npc_name FROM core_npc_master WHERE lower(COALESCE(metadata->'stats'->>'is_dead', 'false')) IN ('true', '1', 't') ORDER BY id DESC LIMIT 20") as $row) {
        if (count($names) >= $limit) {
            break;
        }
        if (!in_array($row['npc_name'], $names, true)) {
            $names[] = $row['npc_name'];
        }
    }
    return $names;
}

/**
 * Did $target die at or after $sinceGamets? Returns null, or ['by_party' => bool, 'line' => string].
 * by_party: the player, a party member or $alsoOwn (the promise's follower) is named as the killer.
 */
function cpeDeathOf(string $target, int $sinceGamets, array $party, string $alsoOwn = ''): ?array
{
    $target = trim($target);
    if ($target === '') {
        return null;
    }
    $killers = array_filter(array_merge([(string)($GLOBALS['PLAYER_NAME'] ?? '')], $party, [$alsoOwn]));
    foreach (array_reverse(cpeDeathRows($sinceGamets)) as $row) {
        $line = cpeDeathText((string)$row['data']);
        $d = cpeDeathParse($line);
        if ($d['victim'] === '' || !cpeNameIn($target, $d['victim'])) {
            continue;
        }
        $byParty = false;
        foreach ($killers as $k) {
            if (cpeNameIn($k, $d['killer'])) {
                $byParty = true;
                break;
            }
        }
        return ['by_party' => $byParty, 'line' => $line];
    }
    return null;
}

function cpeIsEssential(string $npc): bool
{
    $row = cpeOne("SELECT metadata->'stats'->>'is_essential' AS e FROM core_npc_master WHERE npc_name = $1 LIMIT 1", [$npc]);
    return in_array(strtolower((string)($row['e'] ?? '')), ['true', '1', 't'], true);
}

function cpeIsDeadInProfile(string $npc): bool
{
    $row = cpeOne("SELECT metadata->'stats'->>'is_dead' AS d FROM core_npc_master WHERE npc_name = $1 LIMIT 1", [$npc]);
    return in_array(strtolower((string)($row['d'] ?? '')), ['true', '1', 't'], true);
}

/** Key that identifies "the same promise": kind + normalised target/place/title. */
function cpeSubject(string $kind, string $target, string $place, string $title): string
{
    $what = $kind === 'kill' ? $target : ($kind === 'place' ? $place : $title);
    return $kind . ':' . cpeNormalizePlace($what);
}

// ---------------------------------------------------------------------------------------
// Prompts
// ---------------------------------------------------------------------------------------

function cpeLensText(string $lens): string
{
    switch ($lens) {
        case 'aspiration':
            return '{NPC} brings up something {NPC} personally longs to do, see or achieve, true to {NPC}\'s own story and personality, '
                . 'and asks {PLAYER} to do it together some day. It must happen at one real, named place in Skyrim; say the name.';
        case 'activity':
            return '{NPC} suggests something the two of them could do together soon, at a real, named place near here '
                . '(an inn, town, landmark, shop or ruin in Skyrim); say the name.';
        case 'grudge':
            return '{NPC} names one specific living person in Skyrim (a real name, said in the first sentence) whom {NPC} truly wants dead, '
                . 'true to {NPC}\'s own story, and asks {PLAYER} to help kill them some day. Exactly one name, not a list.';
        case 'relationship':
            return '{NPC} shares one honest, personal thought about {PLAYER} or about a companion in the group, '
                . 'based on what they have been through together. No request.';
        default:
            return '{NPC} remarks on something specific here that catches {NPC}\'s interest and wonders about it aloud.';
    }
}

/**
 * Text placed into $gameRequest[3] of the "suggestion" request. CHIM strips the leading "Name:" itself.
 * $avoid: earlier topics / promises; $dead: named people already dead (only sent with proposals).
 */
function cpeInitiativeText(string $npc, string $player, string $lens, array $avoid, array $dead = []): string
{
    $text = '(Nobody has spoken for a while. ' . cpeLensText($lens)
        . ' 1 to 3 short sentences in {NPC}\'s own voice. Do not mention promises, quests, rewards or game mechanics.'
        . ' Anything proposed must be possible now, in Skyrim or Solstheim: a person or place that is there today, not in another province of Tamriel and not in the past.';
    $clip = static fn(array $list): array => array_values(array_unique(array_filter(array_map(static fn($t) => mb_substr(trim((string)$t), 0, 80), $list))));
    $avoid = $clip($avoid);
    if ($avoid) {
        $text .= ' Not these, already raised or done: ' . implode(' / ', array_map(static fn($t) => '"' . $t . '"', $avoid)) . '.';
    }
    $dead = $clip($dead);
    if ($dead) {
        $text .= ' Already dead: ' . implode(', ', $dead) . '.';
    }
    $text .= ')';
    return 'The Narrator: ' . strtr($text, ['{NPC}' => $npc, '{PLAYER}' => $player]);
}

function cpeKeptText(string $npc, string $player, string $title, string $location): string
{
    $text = "({PLAYER} has brought {NPC} to {$location}, as {PLAYER} once promised: \"{$title}\". "
        . '{NPC} notices it and reacts to the promise being kept, in character, in 1 or 2 short sentences.)';
    return 'The Narrator: ' . strtr($text, ['{NPC}' => $npc, '{PLAYER}' => $player]);
}

function cpeKilledText(string $npc, string $player, string $title, string $target): string
{
    $text = "({$target} is dead. {PLAYER} kept a promise to {NPC}: \"{$title}\". "
        . '{NPC} reacts to it, in character, in 1 or 2 short sentences.)';
    return 'The Narrator: ' . strtr($text, ['{NPC}' => $npc, '{PLAYER}' => $player]);
}

function cpeMootText(string $npc, string $player, string $title, string $target): string
{
    $text = "({$target} is dead, but not by {PLAYER}'s or the group's hand, so the promise to {NPC} \"{$title}\" can no longer be kept. "
        . '{NPC} reacts to that, in character, in 1 or 2 short sentences.)';
    return 'The Narrator: ' . strtr($text, ['{NPC}' => $npc, '{PLAYER}' => $player]);
}

/** Rough token count (about 4 characters per token). */
function cpeTokens(string $text): int
{
    return (int)ceil(mb_strlen($text) / 4);
}

/** Weight of each lens (0 = never). Proposal lenses are left out when the follower has enough open promises. */
function cpeLensWeights(array $settings, bool $offersAllowed): array
{
    $weights = [];
    foreach (CPE_LENSES as $lens) {
        $w = max(0, (int)($settings['weight_' . $lens] ?? 0));
        if ($w > 0 && ($offersAllowed || !in_array($lens, CPE_OFFER_LENSES, true))) {
            $weights[$lens] = $w;
        }
    }
    return $weights;
}

/** Pick a lens by weight. */
function cpePickLens(array $settings, bool $offersAllowed, ?callable $rand = null): ?string
{
    $rand = $rand ?? static fn(int $min, int $max): int => random_int($min, $max);
    $weights = cpeLensWeights($settings, $offersAllowed);
    $total = array_sum($weights);
    if ($total <= 0) {
        return null;
    }
    $roll = $rand(1, $total);
    foreach ($weights as $lens => $w) {
        $roll -= $w;
        if ($roll <= 0) {
            return $lens;
        }
    }
    return array_key_last($weights);
}

// ---------------------------------------------------------------------------------------
// Hook entry points
// ---------------------------------------------------------------------------------------

/**
 * Called for every CHIM "bored" event (preprocessing.php, before CHIM routes it).
 * Returns a plan when a follower should speak instead, or null to leave the event to CHIM.
 */
function cpeHandleBored(array $gameRequest, ?callable $rand = null): ?array
{
    $rand = $rand ?? static fn(int $min, int $max): int => random_int($min, $max);
    $settings = cpeSettings();
    if (!cpeOn($settings, 'enabled')) {
        return null;
    }
    if (!cpeDbReady()) {
        cpeLog('warn', 'Plugin tables are missing; reinstall the plugin so its migrations run.');
        return null;
    }
    $party = cpeParty();
    if (!$party) {
        cpeLog('debug', 'Bored event: no followers in the party');
        return null;
    }
    $sinceCombat = cpeSecondsSinceCombat();
    if ($sinceCombat !== null && $sinceCombat < (int)$settings['quiet_after_combat_seconds']) {
        cpeLog('debug', "Bored event: combat {$sinceCombat} s ago, staying quiet");
        return null;
    }

    $pt = cpeCurrentPlaythrough($settings);
    $now = time();
    $gamets = (int)($gameRequest[2] ?? 0);
    cpeExpireOffers($settings, $pt['id']);

    cpeLapseOld($settings, $pt['id'], $gamets);
    $reactionReady = $now - (int)$settings['last_initiative_ts'] >= 60;

    // 1) Deaths: a follower died, or a "kill" promise was kept or can no longer be kept.
    $death = cpeResolveDeaths($pt, $party, $gamets, $reactionReady);
    if ($death !== null) {
        return $death;
    }

    // 2) A place promise was kept (cpeCheckArrival): the follower reacts now.
    cpeCheckArrival($gameRequest);
    if ($reactionReady) {
        foreach (cpeAll('SELECT id, npc, title, place, resolved_gamets FROM ' . CPE_PROMISES . " WHERE playthrough_id = $1 AND status = 'kept' AND close_reason = 'react_pending' ORDER BY id",
            [$pt['id']]) as $promise) {
            $stale = $gamets > 0 && (int)$promise['resolved_gamets'] > 0 && $gamets - (int)$promise['resolved_gamets'] > CPE_GAMETS_PER_DAY;
            if ($stale) {
                cpeOne('UPDATE ' . CPE_PROMISES . " SET close_reason = '' WHERE id = $1 RETURNING id", [(int)$promise['id']]);
                continue;
            }
            if (!in_array($promise['npc'], $party, true)) {
                continue; // reacts when back in the party (within a game day)
            }
            $hash = cpeNpcProfileHash($promise['npc']);
            if ($hash === '') {
                continue;
            }
            cpeOne('UPDATE ' . CPE_PROMISES . " SET close_reason = '' WHERE id = $1 RETURNING id", [(int)$promise['id']]);
            cpeSet('last_initiative_ts', (string)$now);
            return [
                'kind' => 'kept',
                'npc' => $promise['npc'],
                'profile' => $hash,
                'promise_id' => (int)$promise['id'],
                'title' => $promise['title'],
                'notify' => '',
                'playthrough' => $pt,
                'text' => cpeKeptText($promise['npc'], (string)($GLOBALS['PLAYER_NAME'] ?? 'the player'), $promise['title'], $promise['place']),
            ];
        }
    }

    // 3) A new initiative?
    $left = (int)$settings['last_initiative_ts'] + (int)$settings['global_cooldown_minutes'] * 60 - $now;
    if ($left > 0) {
        cpeLog('debug', "Bored event: global cooldown, {$left} s left");
        return null;
    }
    $roll = $rand(1, 100);
    if ($roll > (int)$settings['initiative_chance']) {
        cpeLog('debug', "Bored event: left to CHIM (roll {$roll} > {$settings['initiative_chance']}%)");
        return null;
    }

    // Followers off cooldown, the one who took the initiative longest ago first.
    $candidates = [];
    foreach ($party as $npc) {
        $row = cpeOne('SELECT MAX(created_ts) AS t FROM ' . CPE_INITIATIVES . ' WHERE playthrough_id = $1 AND npc = $2', [$pt['id'], $npc]);
        $last = (int)($row['t'] ?? 0);
        if ($now - $last < (int)$settings['npc_cooldown_minutes'] * 60) {
            continue;
        }
        $candidates[$npc] = $last;
    }
    if (!$candidates) {
        cpeLog('debug', 'Bored event: every follower is on cooldown');
        return null;
    }
    asort($candidates);
    $oldest = reset($candidates);
    $tied = array_keys(array_filter($candidates, static fn($t) => $t === $oldest));
    $npc = $tied[$rand(0, count($tied) - 1)];

    $hash = cpeNpcProfileHash($npc);
    if ($hash === '') {
        cpeLog('warn', "Bored event: no CHIM profile found for {$npc}");
        return null;
    }
    $openCount = (int)(cpeOne('SELECT COUNT(*) AS c FROM ' . CPE_PROMISES . " WHERE playthrough_id = $1 AND npc = $2 AND status IN ('open', 'offered')",
        [$pt['id'], $npc])['c'] ?? 0);
    $lens = cpePickLens($settings, $openCount < (int)$settings['max_open_per_npc'], $rand);
    if ($lens === null) {
        cpeLog('debug', 'Bored event: no lens enabled');
        return null;
    }
    [$avoid, $dead] = cpeAvoidLists($pt['id'], $npc, in_array($lens, CPE_OFFER_LENSES, true));

    $text = cpeInitiativeText($npc, (string)($GLOBALS['PLAYER_NAME'] ?? 'the player'), $lens, $avoid, $dead);
    $added = cpeTokens(preg_replace('/^The Narrator:\s*/', '', $text));
    cpeSet('tokens_last_initiative', (string)$added);
    $initiative = cpeOne('INSERT INTO ' . CPE_INITIATIVES . ' (playthrough_id, npc, lens, created_ts, created_gamets) VALUES ($1, $2, $3, $4, $5) RETURNING id',
        [$pt['id'], $npc, $lens, $now, $gamets]);
    cpeSet('last_initiative_ts', (string)$now);
    cpeIncrement('stat_initiatives');
    cpeLog('info', "Initiative: {$npc} ({$lens}, roll {$roll}, ~{$added} tokens added)");
    return [
        'kind' => 'initiative',
        'npc' => $npc,
        'profile' => $hash,
        'lens' => $lens,
        'initiative_id' => (int)($initiative['id'] ?? 0),
        'playthrough' => $pt,
        'gamets' => $gamets,
        'text' => $text,
    ];
}

/**
 * What the follower should not bring up, capped at CPE_AVOID_MAX entries in total.
 * Talk-only lenses get only recent topics; proposals also get open/unique promises and recent deaths.
 */
function cpeAvoidLists(int $playthroughId, string $npc, bool $proposal): array
{
    $avoid = [];
    foreach (cpeAll('SELECT title FROM ' . CPE_PROMISES . " WHERE playthrough_id = $1 AND npc = $2 AND status IN ('open', 'offered') AND title <> '' ORDER BY id DESC LIMIT 3",
        [$playthroughId, $npc]) as $p) {
        $avoid[] = $p['title'];
    }
    if ($proposal) {
        // Unique promises already made, and anything the player turned down or cancelled (any follower).
        foreach (cpeAll('SELECT title FROM ' . CPE_PROMISES . " WHERE playthrough_id = $1 AND title <> '' AND "
            . "((is_unique AND status IN ('kept', 'impossible', 'open')) OR status IN ('declined', 'cancelled')) ORDER BY id DESC LIMIT 4",
            [$playthroughId]) as $p) {
            $avoid[] = $p['title'];
        }
    }
    foreach (cpeAll('SELECT spoken FROM ' . CPE_INITIATIVES . " WHERE playthrough_id = $1 AND npc = $2 AND spoken <> '' AND NOT rolled_back ORDER BY id DESC LIMIT 2",
        [$playthroughId, $npc]) as $i) {
        $avoid[] = $i['spoken'];
    }
    $avoid = array_slice(array_values(array_unique($avoid)), 0, CPE_AVOID_MAX);
    $dead = $proposal ? cpeRecentDead(max(0, CPE_AVOID_MAX - count($avoid))) : [];
    return [$avoid, $dead];
}

/** Open promises older than the configured number of game days are quietly forgotten. */
function cpeLapseOld(array $settings, int $playthroughId, int $gamets): void
{
    $days = (int)$settings['promise_expiry_days'];
    if ($days <= 0 || $gamets <= 0) {
        return;
    }
    $rows = cpeAll('UPDATE ' . CPE_PROMISES . " SET status = 'lapsed', close_reason = 'too old', resolved_at = CURRENT_TIMESTAMP, resolved_gamets = $1 "
        . "WHERE playthrough_id = $2 AND status = 'open' AND agreed_gamets > 0 AND agreed_gamets < $3 RETURNING npc, title",
        [$gamets, $playthroughId, $gamets - $days * CPE_GAMETS_PER_DAY]);
    foreach ($rows as $r) {
        cpeLog('info', "Promise forgotten (older than {$days} game days): {$r['npc']} - {$r['title']}");
    }
}

/**
 * Deaths since each promise was made:
 * - the promise's follower died: all their open promises can no longer be kept (silently);
 * - a "kill" target died by the party's hand: kept; by someone else: can no longer be kept.
 * Returns a reaction plan for the first one whose follower is in the party (when $reactionReady).
 */
function cpeResolveDeaths(array $pt, array $party, int $gamets, bool $reactionReady): ?array
{
    $open = cpeAll('SELECT id, npc, title, kind, target, agreed_gamets FROM ' . CPE_PROMISES . " WHERE playthrough_id = $1 AND status = 'open' ORDER BY id", [$pt['id']]);
    $plan = null;
    $followerDead = [];
    foreach ($open as $p) {
        $since = (int)$p['agreed_gamets'];
        if (!array_key_exists($p['npc'], $followerDead)) {
            $followerDead[$p['npc']] = cpeDeathOf($p['npc'], $since, []) !== null;
        }
        if ($followerDead[$p['npc']]) {
            cpeOne('UPDATE ' . CPE_PROMISES . " SET status = 'impossible', close_reason = 'follower died', resolved_at = CURRENT_TIMESTAMP, resolved_gamets = $1 WHERE id = $2 RETURNING id",
                [$gamets, (int)$p['id']]);
            cpeLog('info', "Promise closed, {$p['npc']} died: {$p['title']}");
            continue;
        }
        if ($p['kind'] !== 'kill' || trim($p['target']) === '' || $plan !== null) {
            continue;
        }
        $death = cpeDeathOf($p['target'], $since, $party, $p['npc']);
        if ($death === null) {
            continue;
        }
        $kept = $death['by_party'];
        $inParty = in_array($p['npc'], $party, true);
        $hash = $inParty && $reactionReady ? cpeNpcProfileHash($p['npc']) : '';
        if ($inParty && !$reactionReady) {
            continue; // react a little later, when the follower can speak
        }
        cpeOne('UPDATE ' . CPE_PROMISES . " SET status = $1, close_reason = $2, resolved_at = CURRENT_TIMESTAMP, resolved_gamets = $3 WHERE id = $4 RETURNING id",
            [$kept ? 'kept' : 'impossible', $kept ? '' : 'died by another hand', $gamets, (int)$p['id']]);
        cpeIncrement($kept ? 'stat_kept' : 'stat_moot');
        cpeLog('info', ($kept ? 'Promise kept' : 'Promise can no longer be kept') . ": {$p['npc']} - {$p['title']} ({$death['line']})");
        $notify = ($kept ? 'Promise kept: ' : 'Promise can no longer be kept: ') . $p['title'];
        if ($hash === '') {
            if (cpeOn(cpeSettings(), 'notify_kept')) {
                cpeNotify($notify);
            }
            continue;
        }
        cpeSet('last_initiative_ts', (string)time());
        $player = (string)($GLOBALS['PLAYER_NAME'] ?? 'the player');
        $plan = [
            'kind' => $kept ? 'kept' : 'moot',
            'npc' => $p['npc'],
            'profile' => $hash,
            'promise_id' => (int)$p['id'],
            'title' => $p['title'],
            'notify' => $notify,
            'playthrough' => $pt,
            'text' => $kept ? cpeKilledText($p['npc'], $player, $p['title'], $p['target']) : cpeMootText($p['npc'], $player, $p['title'], $p['target']),
        ];
    }
    return $plan;
}

/** After the follower spoke (postrequest.php): store what was said; a proposal becomes an offer. */
function cpeAfterSpeech(array $plan, string $spoken): void
{
    $spoken = trim($spoken);
    if ($plan['kind'] === 'kept' || $plan['kind'] === 'moot') {
        cpeOne('UPDATE ' . CPE_PROMISES . ' SET reaction_text = $1 WHERE id = $2 RETURNING id', [mb_substr($spoken, 0, 2000), $plan['promise_id']]);
        if (($plan['notify'] ?? '') !== '' && cpeOn(cpeSettings(), 'notify_kept')) {
            cpeNotify($plan['notify']);
        }
        return;
    }
    if (!empty($plan['initiative_id'])) {
        cpeOne('UPDATE ' . CPE_INITIATIVES . ' SET spoken = $1 WHERE id = $2 RETURNING id', [mb_substr($spoken, 0, 2000), $plan['initiative_id']]);
    }
    if ($spoken === '' || !in_array($plan['lens'], CPE_OFFER_LENSES, true)) {
        return;
    }
    $pt = $plan['playthrough'];
    // A newer proposal replaces an unanswered one.
    cpeOne('UPDATE ' . CPE_PROMISES . " SET status = 'expired', resolved_at = CURRENT_TIMESTAMP WHERE playthrough_id = $1 AND status = 'offered' RETURNING id", [$pt['id']]);
    cpeOne('INSERT INTO ' . CPE_PROMISES . ' (playthrough_id, playthrough_name, npc, status, lens, offer_text, created_gamets) '
        . "VALUES ($1, $2, $3, 'offered', $4, $5, $6) RETURNING id",
        [$pt['id'], $pt['name'], $plan['npc'], $plan['lens'], mb_substr($spoken, 0, 2000), (int)($plan['gamets'] ?? 0)]);
    cpeIncrement('stat_offers');
    cpeLog('info', "Offer waiting for the player's answer: {$plan['npc']}: " . mb_substr($spoken, 0, 160));
}

function cpeExpireOffers(array $settings, int $playthroughId): void
{
    cpeOne('UPDATE ' . CPE_PROMISES . " SET status = 'expired', resolved_at = CURRENT_TIMESTAMP WHERE playthrough_id = $1 AND status = 'offered' "
        . 'AND created_at < CURRENT_TIMESTAMP - make_interval(mins => $2) RETURNING id', [$playthroughId, max(1, (int)$settings['offer_expiry_minutes'])]);
}

/** The newest offer that still waits for an answer in this playthrough. */
function cpePendingOffer(int $playthroughId): array
{
    $settings = cpeSettings();
    cpeExpireOffers($settings, $playthroughId);
    return cpeOne('SELECT * FROM ' . CPE_PROMISES . " WHERE playthrough_id = $1 AND status = 'offered' ORDER BY id DESC LIMIT 1", [$playthroughId]);
}

/** Remove "Name:" prefix and CHIM context such as "(Context location: ...)" or "(talking to ...)" from a player line. */
function cpeCleanPlayerText(string $text): string
{
    $player = (string)($GLOBALS['PLAYER_NAME'] ?? '');
    if ($player !== '' && stripos($text, $player . ':') === 0) {
        $text = substr($text, strlen($player) + 1);
    }
    $text = preg_replace('/\((?:Context|Talking to|talking to)[^)]*\)/u', '', $text);
    return trim(preg_replace('/\s+/u', ' ', $text));
}

/** After the player answered (postrequest.php of a player input): did they agree to the pending offer? */
function cpeHandlePlayerReply(string $playerText, string $responder, string $responderReply, int $gamets, ?callable $classifier = null): ?string
{
    $settings = cpeSettings();
    if (!cpeOn($settings, 'enabled') || !cpeDbReady()) {
        return null;
    }
    $pt = cpeCurrentPlaythrough($settings);
    $offer = cpePendingOffer($pt['id']);
    if (!$offer) {
        return null;
    }
    $answer = cpeCleanPlayerText($playerText);
    if ($answer === '') {
        return null;
    }
    $reply = strcasecmp($responder, $offer['npc']) === 0 ? trim($responderReply) : '';
    $classifier = $classifier ?? 'cpeClassify';
    $result = $classifier($offer['npc'], (string)($GLOBALS['PLAYER_NAME'] ?? 'Player'), $offer['offer_text'], $answer, $reply);
    $decision = $result['decision'] ?? 'unclear';
    $source = $result['source'] ?? 'llm';

    if ($decision === 'agreed') {
        $title = trim((string)($result['title'] ?? ''));
        if ($title === '') {
            $title = cpeFallbackTitle($offer['offer_text']);
        }
        $kind = in_array($result['kind'] ?? '', CPE_KINDS, true) ? $result['kind'] : 'free';
        $place = trim((string)($result['place'] ?? ''));
        $target = trim((string)($result['target'] ?? ''));
        if ($kind === 'kill' && $target === '') {
            $kind = 'free';
        }
        if ($kind === 'free' && $place !== '') {
            $kind = 'place';
        }
        if ($kind === 'place' && $place === '') {
            $kind = 'free';
        }
        $unique = $kind === 'kill' ? true : !empty($result['unique']);
        $subject = cpeSubject($kind, $target, $place, $title);
        $status = 'open';
        $reason = '';
        if (($result['in_skyrim'] ?? true) === false) {
            $status = 'impossible';
            $reason = 'outside Skyrim';
        } elseif ($kind === 'kill' && (cpeDeathOf($target, 0, []) !== null || cpeIsDeadInProfile($target))) {
            $status = 'impossible';
            $reason = 'already dead';
        } else {
            $same = cpeOne('SELECT id, status FROM ' . CPE_PROMISES . " WHERE playthrough_id = $1 AND subject = $2 AND id <> $3 AND "
                . ($unique ? "status IN ('open', 'kept', 'impossible')" : "status = 'open' AND npc = $4") . ' LIMIT 1',
                $unique ? [$pt['id'], $subject, (int)$offer['id']] : [$pt['id'], $subject, (int)$offer['id'], $offer['npc']]);
            if ($same) {
                $status = 'cancelled';
                $reason = 'duplicate of #' . $same['id'];
            }
        }
        if ($status === 'open' && $kind === 'kill' && cpeIsEssential($target)) {
            $reason = 'essential now (cannot die until the game allows it)';
        }
        cpeOne('UPDATE ' . CPE_PROMISES . ' SET status = $1, title = $2, place = $3, target = $4, kind = $5, is_unique = $6, subject = $7, close_reason = $8, '
            . 'player_answer = $9, agreed_at = CURRENT_TIMESTAMP, agreed_gamets = $10, resolved_at = ' . ($status === 'open' ? 'NULL' : 'CURRENT_TIMESTAMP')
            . ' WHERE id = $11 RETURNING id',
            [$status, mb_substr($title, 0, 200), mb_substr($place, 0, 200), mb_substr($target, 0, 200), $kind, $unique ? 'true' : 'false', mb_substr($subject, 0, 300),
                $reason, mb_substr($answer, 0, 1000), $gamets, (int)$offer['id']]);
        if ($status !== 'open') {
            cpeLog('info', "Agreed, but not stored as a promise ({$reason}): {$offer['npc']} - {$title}");
            return $status;
        }
        cpeIncrement('stat_agreed');
        cpeLog('info', "Promise made ({$source}, {$kind}" . ($unique ? ', unique' : '') . "): {$offer['npc']} - {$title}"
            . ($kind === 'kill' ? " (target {$target})" : ($place !== '' ? " @ {$place}" : '')));
        if (cpeOn($settings, 'notify_remember')) {
            cpeNotify($offer['npc'] . ' will remember this.');
        }
        return 'agreed';
    }
    if ($decision === 'declined') {
        // Keep what was proposed, so it is not proposed again soon.
        $dKind = in_array($result['kind'] ?? '', CPE_KINDS, true) ? $result['kind'] : 'free';
        $dTitle = mb_substr(trim((string)($result['title'] ?? '')), 0, 200);
        $dTarget = mb_substr(trim((string)($result['target'] ?? '')), 0, 200);
        $dPlace = mb_substr(trim((string)($result['place'] ?? '')), 0, 200);
        cpeOne('UPDATE ' . CPE_PROMISES . " SET status = 'declined', player_answer = $1, title = $2, kind = $3, target = $4, place = $5, subject = $6, "
            . 'resolved_at = CURRENT_TIMESTAMP WHERE id = $7 RETURNING id',
            [mb_substr($answer, 0, 1000), $dTitle, $dKind, $dTarget, $dPlace, $dTitle !== '' ? cpeSubject($dKind, $dTarget, $dPlace, $dTitle) : '', (int)$offer['id']]);
        cpeIncrement('stat_declined');
        cpeLog('info', "Offer declined ({$source}): {$offer['npc']}");
        return 'declined';
    }
    $tries = (int)$offer['tries'] + 1;
    cpeOne('UPDATE ' . CPE_PROMISES . ' SET tries = $1' . ($tries >= 2 ? ", status = 'expired', resolved_at = CURRENT_TIMESTAMP" : '') . ' WHERE id = $2 RETURNING id',
        [$tries, (int)$offer['id']]);
    cpeLog('debug', "Answer unclear ({$source}, try {$tries}): " . mb_substr($answer, 0, 120));
    return 'unclear';
}

function cpeFallbackTitle(string $offer): string
{
    // The first sentence that says something (skip "Hey, Martin." and the like).
    foreach (preg_split('/(?<=[.!?])\s+/u', trim($offer)) as $sentence) {
        $sentence = trim($sentence);
        if (count(preg_split('/\s+/u', $sentence)) >= 5) {
            return mb_strlen($sentence) > 80 ? mb_substr($sentence, 0, 77) . '...' : $sentence;
        }
    }
    return mb_substr(trim($offer) !== '' ? trim($offer) : 'Promise', 0, 80);
}

// ---------------------------------------------------------------------------------------
// Save loads: CHIM forgets everything said after the loaded moment, so do promises.
// ---------------------------------------------------------------------------------------

/** Game time of the last game save CHIM recorded (used when the player dies). */
function cpeLastSaveGamets(): int
{
    $row = cpeOne("SELECT gamets FROM eventlog WHERE type = 'infosave' ORDER BY ts DESC LIMIT 1");
    return (int)($row['gamets'] ?? 0);
}

/**
 * Forget what happened at or after game time $gamets in the current playthrough, like CHIM does:
 * promises made then are deleted, promises kept then are open again, initiatives from then are deleted.
 * Returns the number of changed rows.
 */
function cpeRollback(int $gamets, string $reason): int
{
    if ($gamets <= 0 || !cpeDbReady()) {
        return 0;
    }
    $pt = cpeCurrentPlaythrough();
    $changed = count(cpeAll('DELETE FROM ' . CPE_PROMISES . ' WHERE playthrough_id = $1 AND GREATEST(agreed_gamets, created_gamets) >= $2 RETURNING id',
        [$pt['id'], $gamets]));
    $changed += count(cpeAll('UPDATE ' . CPE_PROMISES . " SET status = 'open', reaction_text = '', close_reason = '', resolved_at = NULL, resolved_gamets = 0 "
        . "WHERE playthrough_id = $1 AND status IN ('kept', 'impossible', 'lapsed') AND resolved_gamets >= $2 RETURNING id", [$pt['id'], $gamets]));
    // Hidden, not deleted: the follower forgot saying it, but turn-taking between followers stays fair.
    $changed += count(cpeAll('UPDATE ' . CPE_INITIATIVES . ' SET rolled_back = TRUE WHERE playthrough_id = $1 AND created_gamets >= $2 AND NOT rolled_back RETURNING id', [$pt['id'], $gamets]));
    if ($changed > 0) {
        cpeLog('info', "{$reason}: forgot {$changed} promise/initiative row(s) from game time {$gamets} on");
    }
    return $changed;
}

// ---------------------------------------------------------------------------------------
// Agreement check (one short LLM call, keyword fallback)
// ---------------------------------------------------------------------------------------

function cpeClassifierMessages(string $npc, string $player, string $offer, string $answer, string $reply): array
{
    $offer = mb_strlen($offer) > 500 ? mb_substr($offer, 0, 500) . '...' : $offer;
    $user = "{$npc} suggested: \"{$offer}\"\n{$player} answered: \"{$answer}\"\n"
        . ($reply !== '' ? "{$npc} replied: \"{$reply}\"\n" : '')
        . "Did {$player} agree to do it later? Fill in title, kind, target and place even if {$player} declined.\n"
        . 'Return JSON only: {"decision":"agreed|declined|unclear","title":"...","kind":"place|kill|free","target":"...","place":"...","unique":true,"in_skyrim":true}' . "\n"
        . "title: what {$player} promised, at most 8 words, starting with a verb and naming who or where, e.g. \"Kill Ondolemar for {$npc}\" or \"Share a drink with {$npc} at the Bee and Barb\".\n"
        . "kind: kill = a named person or creature must die; place = something to do at one named Skyrim location; free = anything else.\n"
        . 'target: for kill, the exact name of who must die, else "". place: the named location as Skyrim names it, else "".' . "\n"
        . 'unique: true if it can only happen once (a killing, a first visit to a special place), false if it can be repeated (a drink, training).' . "\n"
        . 'in_skyrim: false if the person or place is outside Skyrim and Solstheim (another province of Tamriel) or no longer exists.';
    return [
        ['role' => 'system', 'content' => 'You read a short exchange from a Skyrim roleplay and decide whether a plan was agreed. Answer with JSON only.'],
        ['role' => 'user', 'content' => $user],
    ];
}

function cpeParseClassification(string $raw): ?array
{
    if (!preg_match('/\{.*\}/su', $raw, $m)) {
        return null;
    }
    $data = json_decode($m[0], true);
    if (!is_array($data)) {
        return null;
    }
    $decision = strtolower(trim((string)($data['decision'] ?? '')));
    if (!in_array($decision, ['agreed', 'declined', 'unclear'], true)) {
        return null;
    }
    return [
        'decision' => $decision,
        'title' => trim((string)($data['title'] ?? '')),
        'place' => trim((string)($data['place'] ?? '')),
        'kind' => strtolower(trim((string)($data['kind'] ?? 'free'))),
        'target' => trim((string)($data['target'] ?? '')),
        'unique' => filter_var($data['unique'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'in_skyrim' => filter_var($data['in_skyrim'] ?? true, FILTER_VALIDATE_BOOLEAN),
    ];
}

/** Used when no LLM answer could be read. English keywords only. */
function cpeKeywordDecision(string $answer): string
{
    $a = ' ' . mb_strtolower($answer) . ' ';
    if (preg_match("/\b(no|nope|never|not now|not interested|maybe later|don't think so|i can't|i won't)\b/u", $a)) {
        return 'declined';
    }
    if (preg_match("/\b(yes|yeah|yep|sure|of course|okay|ok|deal|gladly|i promise|agreed|why not|let's|lets do|sounds good|count me in|i'd like that|i would like that)\b/u", $a)) {
        return 'agreed';
    }
    return 'unclear';
}

function cpeLlmConnectorData(): ?array
{
    $data = $GLOBALS['CHIM_CORE_CURRENT_CONNECTOR_DATA'] ?? ($GLOBALS['currentConnectorData'] ?? null);
    if (!is_array($data) || empty($data['driver'])) {
        return null;
    }
    if (function_exists('chimIsDecisionConnector') && chimIsDecisionConnector($data)) {
        return null;
    }
    return $data;
}

/** Connectors to try for the agreement check: the NPC's own, then CHIM's medium-term and scene classifier ones. */
function cpeClassifierConnectors(): array
{
    $list = [];
    $own = cpeLlmConnectorData();
    if ($own) {
        $list[] = $own;
    }
    if (class_exists('LLMConnector')) {
        foreach (['CORE_CONNECTOR_MEDIUMTERM', 'CORE_CONNECTOR_SCENECLASSIFIER'] as $key) {
            $id = (int)($GLOBALS[$key] ?? 0);
            if ($id <= 0 || ($own && (int)($own['id'] ?? 0) === $id)) {
                continue;
            }
            try {
                $data = (new LLMConnector())->getById($id);
                if (is_array($data) && !empty($data['driver']) && !(function_exists('chimIsDecisionConnector') && chimIsDecisionConnector($data))) {
                    $list[] = $data;
                }
            } catch (Throwable $e) {
            }
        }
    }
    return $list;
}

function cpeClassify(string $npc, string $player, string $offer, string $answer, string $reply): array
{
    $messages = cpeClassifierMessages($npc, $player, $offer, $answer, $reply);
    cpeSet('tokens_last_check', (string)cpeTokens(implode("\n", array_column($messages, 'content'))));
    $connectors = cpeClassifierConnectors();
    if (!$connectors) {
        cpeLog('warn', 'Agreement check: no chat LLM connector available, using keywords');
    }
    foreach ($connectors as $data) {
        $name = ($data['label'] ?? '') !== '' ? $data['label'] : (($data['driver'] ?? '?') . '/' . ($data['model'] ?? '?'));
        $raw = '';
        try {
            // CHIM connectors read their URL, key and model from globals; load this connector's first
            // (another step of the request, e.g. CHIM's scene classifier, may have switched them).
            $connector = new LLMConnector();
            $connector->setOldGlobals($data);
            $raw = (string)$connector->getConnector($data)->fast_request($messages, ['MAX_TOKENS' => 300], 'promise_engine');
        } catch (Throwable $e) {
            cpeLog('warn', "Agreement check failed on {$name}: " . $e->getMessage());
            continue;
        }
        $parsed = cpeParseClassification($raw);
        if ($parsed) {
            return $parsed + ['source' => 'llm'];
        }
        cpeLog('warn', "Agreement check on {$name} returned " . ($raw === '' ? 'nothing' : 'no JSON: ' . mb_substr($raw, 0, 200)));
    }
    // Without an LLM answer only a clear "yes" counts; anything else waits for the next line.
    $decision = cpeKeywordDecision($answer) === 'agreed' ? 'agreed' : 'unclear';
    return ['decision' => $decision, 'title' => '', 'place' => '', 'kind' => 'free', 'target' => '', 'unique' => false, 'source' => 'keywords'];
}

// ---------------------------------------------------------------------------------------
// Plugin page helpers
// ---------------------------------------------------------------------------------------

function cpePromises(int $playthroughId): array
{
    return cpeAll('SELECT * FROM ' . CPE_PROMISES . ' WHERE playthrough_id = $1 ORDER BY '
        . "CASE status WHEN 'open' THEN 0 WHEN 'offered' THEN 1 WHEN 'kept' THEN 2 ELSE 3 END, id DESC LIMIT 300", [$playthroughId]);
}

function cpeRecentInitiatives(int $playthroughId, int $limit = 30): array
{
    return cpeAll('SELECT * FROM ' . CPE_INITIATIVES . ' WHERE playthrough_id = $1 AND NOT rolled_back ORDER BY id DESC LIMIT $2', [$playthroughId, $limit]);
}

function cpeLogFetch(int $limit = 200): array
{
    if (!cpeTableExists(CPE_LOG)) {
        return [];
    }
    return cpeAll('SELECT id, created_at, level, message FROM ' . CPE_LOG . ' ORDER BY id DESC LIMIT $1', [$limit]);
}

function cpeDiagnosticReport(): string
{
    $s = cpeSettings(true);
    $pt = cpeCurrentPlaythrough($s);
    $out = [CPE_NAME . ' ' . CPE_VERSION . ' diagnostics, ' . gmdate('Y-m-d H:i:s') . ' UTC', ''];
    $out[] = 'Tables ready: ' . (cpeDbReady() ? 'yes' : 'NO');
    $out[] = 'Current playthrough: ' . $pt['id'] . ' ' . $pt['name'] . ' (' . $pt['source'] . ')';
    $out[] = 'Party: ' . implode(', ', cpeParty());
    $out[] = 'Turn order (last initiative per follower, real time):';
    foreach (cpeParty() as $npc) {
        $t = (int)(cpeOne('SELECT MAX(created_ts) AS t FROM ' . CPE_INITIATIVES . ' WHERE playthrough_id = $1 AND npc = $2', [$pt['id'], $npc])['t'] ?? 0);
        $out[] = '  ' . $npc . ': ' . ($t > 0 ? gmdate('Y-m-d H:i:s', $t) . ' UTC' : 'never');
    }
    $out[] = '';
    $out[] = 'Settings:';
    foreach ($s as $k => $v) {
        $out[] = "  {$k} = {$v}";
    }
    $out[] = '';
    $out[] = 'Promises in this playthrough:';
    foreach (cpePromises($pt['id']) as $p) {
        $out[] = "  #{$p['id']} {$p['status']} {$p['kind']}" . ($p['is_unique'] === 't' ? ' unique' : '') . " {$p['npc']}: {$p['title']}"
            . ($p['target'] !== '' ? " [target {$p['target']}]" : '') . ($p['place'] !== '' ? " @ {$p['place']}" : '') . ($p['close_reason'] !== '' ? " ({$p['close_reason']})" : '');
    }
    $out[] = '';
    $out[] = 'Recent initiatives (what the follower said):';
    foreach (cpeRecentInitiatives($pt['id'], 15) as $i) {
        $out[] = "  {$i['created_at']} {$i['npc']} ({$i['lens']}): " . ($i['spoken'] !== '' ? mb_substr($i['spoken'], 0, 300) : '(no reply recorded)');
    }
    $out[] = '';
    $out[] = 'Log (newest first):';
    foreach (cpeLogFetch(300) as $row) {
        $out[] = "  {$row['created_at']} [{$row['level']}] {$row['message']}";
    }
    return implode("\n", $out) . "\n";
}

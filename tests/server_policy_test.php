<?php
/**
 * CHIM - Promise Engine: server checks.
 *   php tests/server_policy_test.php                 pure checks (CI)
 *   CPE_TEST_PG="host=... dbname=..." php tests/...  also runs the database flow against a scratch PostgreSQL
 */

require __DIR__ . '/../lib/promise_engine.php';

$failures = 0;
function check(string $name, bool $ok): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . "\n";
    if (!$ok) $failures++;
}

// Place matching
check('exact place', cpePlaceMatches('Riften', 'Riften'));
check('article ignored', cpePlaceMatches('The Bee and Barb', 'Bee and Barb'));
check('inn inside location name', cpePlaceMatches('Bee and Barb', 'The Bee and Barb'));
check('city does not fulfil inn', !cpePlaceMatches('The Bee and Barb', 'Riften'));
check('partial word does not match', !cpePlaceMatches('Riv', 'Riverwood'));
check('empty place never matches', !cpePlaceMatches('', 'Riften'));
check('location parts', cpeLocationParts('x (Context location: Vilemyr Inn, Ivarstead, Hold: The Rift)') === ['Vilemyr Inn', 'Ivarstead', 'The Rift']);
check('date and weather are not places', !in_array('Pleasant', cpeLocationParts('(Context location: Ivarstead, The Rift, current date Middas, 20:28, Pleasant)'), true));
check('fallback title skips greetings', cpeFallbackTitle('Hey, Martin. Shroud Hearth Barrow is just up the road from here.') === 'Shroud Hearth Barrow is just up the road from here.');
check('place found in any part', cpePlaceMatchesAny('Ivarstead', cpeLocationParts('(Context location: Vilemyr Inn interior, Ivarstead, Hold: The Rift)')));
check('location from request', cpeCurrentLocation('Serana: bored (Context location: Riften outdoors, Hold: The Rift)') === 'Riften');

// Death lines
check('death: killer and victim', cpeDeathParse('Lydia killed Nazeem with Steel Sword') === ['victim' => 'Nazeem', 'killer' => 'Lydia']);
check('death: died', cpeDeathParse('(Context location: Whiterun) Nazeem died')['victim'] === 'Nazeem');
check('death: CHIM format', cpeDeathParse('Sofia has defeated Sinding(powerful enemy) using weapon Steel Sword') === ['victim' => 'Sinding', 'killer' => 'Sofia']);
check('death: killed by', cpeDeathParse('Nazeem was killed by a dragon')['killer'] === 'a dragon');
check('name match is whole word', cpeNameIn('Nazeem', 'Nazeem') && !cpeNameIn('Naz', 'Nazeem'));
check('subject key', cpeSubject('kill', 'Nazeem', '', 'x') === 'kill:nazeem' && cpeSubject('place', '', 'The Bee and Barb', 'x') === 'place:bee and barb');

// Classifier parsing
$p = cpeParseClassification("Sure.\n```json\n{\"decision\":\"agreed\",\"title\":\"Share a drink with Serana\",\"place\":\"The Bee and Barb\"}\n```");
check('json inside text', $p !== null && $p['decision'] === 'agreed' && $p['place'] === 'The Bee and Barb');
$k = cpeParseClassification('{"decision":"agreed","title":"Kill Nazeem","kind":"kill","target":"Nazeem","place":"","unique":true}');
check('kind and target parsed', $k['kind'] === 'kill' && $k['target'] === 'Nazeem' && $k['unique'] === true && $k['in_skyrim'] === true);
check('outside Skyrim parsed', cpeParseClassification('{"decision":"agreed","in_skyrim":false}')['in_skyrim'] === false);
check('bad decision rejected', cpeParseClassification('{"decision":"maybe"}') === null);
check('no json', cpeParseClassification('agreed') === null);

// Keyword fallback
check('keywords: yes', cpeKeywordDecision("Sure, let's do that.") === 'agreed');
check('keywords: no', cpeKeywordDecision('No, not now.') === 'declined');
check('no LLM: a "no" does not decline, it waits', cpeClassify('Sieglinde', 'Martin', 'Kill him?', 'No one deserves it more. Let us do it.', '')['decision'] !== 'declined');
check('keywords: unclear', cpeKeywordDecision('Hmm, the weather is cold.') === 'unclear');

// Lenses
$s = cpeDefaults();
check('lens chosen', in_array(cpePickLens($s, true, fn($a, $b) => 0), CPE_LENSES, true));
check('no offers when too many promises', !in_array(cpePickLens($s, false, fn($a, $b) => 0), CPE_OFFER_LENSES, true));
$s2 = $s; foreach (CPE_LENSES as $l) $s2['weight_' . $l] = '0';
$s3 = $s2; $s3['weight_activity'] = '1';
check('weights: only weighted lens picked', cpePickLens($s3, true, fn($a, $b) => $b) === 'activity');
$counts = array_fill_keys(CPE_LENSES, 0); for ($i = 0; $i < 4000; $i++) $counts[cpePickLens($s, true)]++;
check('weights: default share roughly follows weights', abs($counts['aspiration'] / 4000 - 0.30) < 0.04 && abs($counts['grudge'] / 4000 - 0.10) < 0.03);
check('all lenses off', cpePickLens($s2, true) === null);

// CHIM removes the leading "Name:" of a suggestion with this regex; our text must survive it.
$text = cpeInitiativeText('Serana', 'Dovahkiin', 'aspiration', ['Earlier: topic']);
$stripped = preg_replace('/^[^:]+:\s*/', '', $text);
check('suggestion prefix stripped cleanly', str_starts_with($stripped, '(Nobody has spoken') && str_contains($stripped, 'Serana'));
$GLOBALS['PLAYER_NAME'] = 'Dovahkiin';
check('player clean', cpeCleanPlayerText('Dovahkiin: Sure, why not. (Talking to Serana)') === 'Sure, why not.');

// No hook names inside lib/
foreach (glob(__DIR__ . '/../lib/*') as $f) {
    check('lib file is not a hook name: ' . basename($f), !in_array(basename($f), ['preprocessing.php', 'postrequest.php', 'prompts.php', 'prerequest.php', 'context.php', 'globals.php', 'functions.php'], true));
}

// ---------------------------------------------------------------------------------------
$dsn = getenv('CPE_TEST_PG');
if ($dsn) {
    require __DIR__ . '/fake_sql.php';
    $db = new sql($dsn);
    $GLOBALS['db'] = $db;
    $GLOBALS['PLAYER_NAME'] = 'Dovahkiin';
    foreach (['DROP SCHEMA IF EXISTS plugins CASCADE', 'DROP SCHEMA IF EXISTS chim_meta CASCADE', 'DROP TABLE IF EXISTS eventlog, responselog, core_npc_master, conf_opts']
        as $q) $db->fetchAll($q);
    foreach (glob(__DIR__ . '/../migrations/*.sql') as $m) $db->fetchAll(file_get_contents($m));
    $db->fetchAll("CREATE TABLE eventlog (type text, localts bigint, data text, gamets bigint, ts bigint)");
    $db->fetchAll("CREATE TABLE responselog (rowid serial, localts bigint, sent int, actor text, text text, action text, tag text)");
    $db->fetchAll("CREATE TABLE core_npc_master (id serial, npc_name text, md5 text, metadata jsonb)");
    $db->fetchAll("INSERT INTO core_npc_master (npc_name, md5) VALUES ('Serana', md5('Serana')), ('Lydia', md5('Lydia'))");
    $db->fetchAll("CREATE SCHEMA chim_meta; CREATE TABLE chim_meta.playthrough_profiles (id int, name text, is_active boolean, created_at timestamp default now())");
    $db->fetchAll("INSERT INTO chim_meta.playthrough_profiles (id, name, is_active) VALUES (1, 'Default', false), (7, 'Vampire run', true)");
    function DataGetCurrentPartyConf() { return json_encode(['Serana' => ['name' => 'Serana'], 'Lydia' => ['name' => 'Lydia']]); }

    $pt = cpeCurrentPlaythrough();
    check('db: active playthrough followed', $pt['id'] === 7 && $pt['source'] === 'auto');

    cpeSet('initiative_chance', '100');
    cpeSet('weight_relationship', '0');
    cpeSet('weight_curiosity', '0');
    $plan = cpeHandleBored(['bored', '1', '1000', 'x (Context location: Whiterun outdoors)', ''], fn($a, $b) => $a);
    check('db: initiative planned', $plan !== null && $plan['kind'] === 'initiative' && $plan['profile'] === md5($plan['npc']));
    $again = cpeHandleBored(['bored', '1', '1000', '', ''], fn($a, $b) => $a);
    check('db: global cooldown blocks the next one', $again === null);

    cpeAfterSpeech($plan, 'When this is over, I want a drink at the Bee and Barb in Riften. Will you come with me?');
    $offer = cpePendingOffer(7);
    check('db: offer stored', ($offer['status'] ?? '') === 'offered');

    $result = cpeHandlePlayerReply('Dovahkiin: Of course, I promise.', $plan['npc'], 'Good.', 1200,
        fn() => ['decision' => 'agreed', 'title' => 'Share a drink at the Bee and Barb', 'place' => 'The Bee and Barb', 'source' => 'test']);
    $open = cpeOne("SELECT * FROM plugins.chim_promise_promises WHERE status = 'open'");
    check('db: promise opened', $result === 'agreed' && ($open['place'] ?? '') === 'The Bee and Barb' && (int)$open['playthrough_id'] === 7);
    $note = cpeOne('SELECT action FROM responselog ORDER BY rowid DESC LIMIT 1');
    check('db: remember notification queued', ($note['action'] ?? '') === 'rolecommand|DebugNotification@' . $plan['npc'] . ' will remember this.');

    check('db: arrival needs the right place', cpeCheckArrival(['location', '1', '1900', '(Context location: Riften, Hold: The Rift)']) === 0);
    cpeSet('last_initiative_ts', '0');
    $none = cpeHandleBored(['bored', '1', '2000', 'x (Context location: Riften outdoors)', ''], fn($a, $b) => $b);
    check('db: wrong place does not keep the promise', $none === null || $none['kind'] !== 'kept');
    $arrive = ['location', '1', '3000', '(Context location: The Bee and Barb interior, Riften, Hold: The Rift, current date Middas, 20:28, Pleasant)'];
    check('db: arriving keeps the promise', cpeCheckArrival($arrive) === 1);
    check('db: a second request at the same moment does not keep it twice', cpeCheckArrival($arrive) === 0);
    $rows = array_column(cpeAll('SELECT action FROM responselog ORDER BY rowid DESC LIMIT 2'), 'action');
    check('db: kept notification queued', in_array('rolecommand|DebugNotification@Promise kept: Share a drink at the Bee and Barb', $rows, true));
    check('db: reaction queued right away', (bool)preg_match('/^rolecommand\|Suggestion@\w+@[^@:|]+@cpe/', $rows[0] ?? ''));
    check('db: no reaction left for the next quiet moment', cpeOne("SELECT close_reason FROM plugins.chim_promise_promises WHERE title = 'Share a drink at the Bee and Barb'")['close_reason'] === '');

    $db->fetchAll("INSERT INTO eventlog (type, localts) VALUES ('combatend', " . time() . ")");
    cpeSet('last_initiative_ts', '0');
    check('db: quiet after combat', cpeHandleBored(['bored', '1', '4000', '', ''], fn($a, $b) => $a) === null);

    // Save loads: promise agreed at 1200 and kept at 3000.
    cpeRollback(2500, 'test load');
    $row = cpeOne("SELECT status, reaction_text FROM plugins.chim_promise_promises WHERE title = 'Share a drink at the Bee and Barb'");
    check('db: load before keeping reopens the promise', ($row['status'] ?? '') === 'open' && $row['reaction_text'] === '');
    cpeRollback(1100, 'test load');
    check('db: load before the promise deletes it', !cpeOne("SELECT id FROM plugins.chim_promise_promises WHERE title = 'Share a drink at the Bee and Barb'"));
    check('db: initiatives after the load are forgotten', (int)cpeOne('SELECT COUNT(*) AS c FROM plugins.chim_promise_initiatives WHERE created_gamets >= 1100 AND NOT rolled_back')['c'] === 0);
    check('db: hidden initiatives still count for turn-taking', (int)cpeOne('SELECT COUNT(*) AS c FROM plugins.chim_promise_initiatives WHERE rolled_back')['c'] > 0);
    $db->fetchAll("INSERT INTO eventlog (type, gamets, ts) VALUES ('infosave', 900, 1)");
    check('db: last save time', cpeLastSaveGamets() === 900);
    check('db: rollback with no time does nothing', cpeRollback(0, 'x') === 0);

    // Kill promises
    $db->fetchAll("DELETE FROM eventlog WHERE type = 'combatend'");
    $kill = function (string $target, string $npc = 'Serana', int $at = 5000) {
        cpeOne("INSERT INTO plugins.chim_promise_promises (playthrough_id, playthrough_name, npc, status, lens, offer_text, created_gamets) VALUES (7, 'Vampire run', $1, 'offered', 'grudge', 'I want him dead.', $2) RETURNING id", [$npc, $at]);
        return cpeHandlePlayerReply('Dovahkiin: Fine, he dies.', $npc, '', $at + 10,
            fn() => ['decision' => 'agreed', 'title' => "Kill $target", 'kind' => 'kill', 'target' => $target, 'place' => '', 'unique' => true, 'source' => 'test']);
    };
    check('db: kill promise made', $kill('Nazeem') === 'agreed');
    check('db: same unique promise again is a duplicate', $kill('Nazeem', 'Lydia') === 'cancelled');
    $db->fetchAll("INSERT INTO eventlog (type, gamets, ts, data) VALUES ('death', 4000, 2, 'Heimskr died')");
    check('db: target already dead is refused', $kill('Heimskr') === 'impossible');
    cpeOne("INSERT INTO plugins.chim_promise_promises (playthrough_id, playthrough_name, npc, status, lens, offer_text, created_gamets) VALUES (7, 'Vampire run', 'Serana', 'offered', 'grudge', 'In Cyrodiil...', 4500) RETURNING id");
    check('db: target outside Skyrim is refused', cpeHandlePlayerReply('Dovahkiin: Sure.', 'Serana', '', 4510,
        fn() => ['decision' => 'agreed', 'title' => 'Kill a man in Cyrodiil', 'kind' => 'kill', 'target' => 'Some Imperial', 'place' => 'Cyrodiil', 'unique' => true, 'in_skyrim' => false, 'source' => 'test']) === 'impossible');
    $db->fetchAll("INSERT INTO core_npc_master (npc_name, md5, metadata) VALUES ('Ancano', md5('Ancano'), '{\"stats\":{\"is_essential\":true}}'), ('Nelacar', md5('Nelacar'), '{\"stats\":{\"is_dead\":true}}')");
    check('db: essential target is noted', $kill('Ancano', 'Lydia', 4600) === 'agreed'
        && str_starts_with(cpeOne("SELECT close_reason FROM plugins.chim_promise_promises WHERE target = 'Ancano'")['close_reason'], 'essential'));
    check('db: dead in profile is refused', $kill('Nelacar', 'Lydia', 4700) === 'impossible');
    check('db: dead in profile is hinted', in_array('Nelacar', cpeRecentDead(8), true));
    cpeOne("INSERT INTO plugins.chim_promise_promises (playthrough_id, playthrough_name, npc, status, lens, offer_text, created_gamets) VALUES (7, 'Vampire run', 'Lydia', 'offered', 'grudge', 'Maven must die.', 4800) RETURNING id");
    cpeHandlePlayerReply('Dovahkiin: No.', 'Lydia', '', 4810, fn() => ['decision' => 'declined', 'title' => 'Kill Maven Black-Briar', 'kind' => 'kill', 'target' => 'Maven Black-Briar', 'place' => '', 'unique' => true, 'source' => 'test']);
    check('db: a declined target is not proposed again soon', in_array('Kill Maven Black-Briar', cpeAvoidLists(7, 'Serana', true)[0], true));
    check('db: recently dead are hinted', in_array('Heimskr', cpeRecentDead(5), true));
    $db->fetchAll("INSERT INTO eventlog (type, gamets, ts, data) VALUES ('death', 6000, 3, 'Dovahkiin killed Nazeem with Iron Dagger')");
    cpeSet('last_initiative_ts', '0');
    cpeHandleDeathEvent(6000);
    check('db: kill by the player keeps the promise at once', cpeOne("SELECT status FROM plugins.chim_promise_promises WHERE target = 'Nazeem' AND npc = 'Serana'")['status'] === 'kept'
        && str_starts_with(cpeOne('SELECT action FROM responselog ORDER BY rowid DESC LIMIT 1 OFFSET 1')['action'] ?? '', 'rolecommand|Suggestion@Serana@'));
    $kill('Ulfric', 'Serana', 7000);
    $db->fetchAll("INSERT INTO eventlog (type, gamets, ts, data) VALUES ('death', 7100, 4, 'Ulfric Stormcloak was killed by General Tullius')");
    $db->fetchAll("UPDATE plugins.chim_promise_promises SET target = 'Ulfric Stormcloak', subject = 'kill:ulfric stormcloak' WHERE target = 'Ulfric'");
    cpeSet('last_initiative_ts', '0');
    $r = cpeHandleBored(['bored', '1', '7200', '', ''], fn($a, $b) => $b);
    check('db: killed by someone else is moot', $r !== null && $r['kind'] === 'moot');
    cpeRollback(7050, 'test load');
    check('db: load reopens a moot promise', cpeOne("SELECT status FROM plugins.chim_promise_promises WHERE target = 'Ulfric Stormcloak'")['status'] === 'open');
    $db->fetchAll("DELETE FROM eventlog WHERE gamets >= 7050");
    $kill('Maven Black-Briar', 'Lydia', 7300);
    $db->fetchAll("INSERT INTO eventlog (type, gamets, ts, data) VALUES ('death', 7400, 5, 'A bandit killed Lydia')");
    cpeSet('last_initiative_ts', '0');
    cpeHandleBored(['bored', '1', '7500', '', ''], fn($a, $b) => $b);
    check('db: follower death closes their promises', cpeOne("SELECT close_reason FROM plugins.chim_promise_promises WHERE target = 'Maven Black-Briar' AND status <> 'declined'")['close_reason'] === 'follower died');
    check('db: killer named in a line is not the victim', cpeOne("SELECT status FROM plugins.chim_promise_promises WHERE target = 'Ulfric Stormcloak'")['status'] === 'open');
    cpeSet('promise_expiry_days', '1');
    cpeSet('last_initiative_ts', '0');
    cpeHandleBored(['bored', '1', (string)(7300 + 2 * CPE_GAMETS_PER_DAY), '', ''], fn($a, $b) => $b);
    check('db: old promises are forgotten', cpeOne("SELECT status FROM plugins.chim_promise_promises WHERE target = 'Ulfric Stormcloak'")['status'] === 'lapsed');
    [$av, $dd] = cpeAvoidLists(7, 'Serana', true);
    check('db: avoid list is capped', count($av) + count($dd) <= CPE_AVOID_MAX);

    cpeSet('playthrough_override', '1');
    check('db: manual override', cpeCurrentPlaythrough()['id'] === 1);
    check('db: tabs list both playthroughs', count(cpePlaythroughs()) === 2);
    check('db: report builds', str_contains(cpeDiagnosticReport(), 'Vampire run') || str_contains(cpeDiagnosticReport(), 'Default'));
}

echo $failures ? "\n{$failures} check(s) failed\n" : "\nAll checks passed\n";
exit($failures ? 1 : 0);

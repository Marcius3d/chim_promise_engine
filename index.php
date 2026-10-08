<?php
/**
 * CHIM - Promise Engine: plugin page (CHIM web UI -> Server Plugins -> CHIM - Promise Engine).
 */

$enginePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
require_once $enginePath . 'lib' . DIRECTORY_SEPARATOR . 'runtime_bootstrap.php';
chimRuntimeBootstrap($enginePath, [
    'load_general_settings' => true,
    'load_player_name' => true,
    'load_narrator' => false,
]);
$GLOBALS['db'] = $GLOBALS['db'] ?? new sql();

require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'promise_engine.php';

function peH($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function peChecked(array $settings, string $key): string
{
    return ($settings[$key] ?? '0') === '1' ? 'checked' : '';
}

function peDef(string $key, string $unit = ''): string
{
    $d = cpeDefaults()[$key] ?? '';
    return ' <span class="def">default: ' . peH($d) . peH($unit) . '</span>';
}

/** Current share of a lens in percent, from the saved weights. */
function peShare(array $settings, string $lens): string
{
    $weights = cpeLensWeights($settings, true);
    $total = array_sum($weights);
    return $total > 0 ? round(100 * ($weights[$lens] ?? 0) / $total) . ' %' : '–';
}

function peDefOnOff(string $key): string
{
    return ' <span class="def">default: ' . ((cpeDefaults()[$key] ?? '0') === '1' ? 'on' : 'off') . '</span>';
}

const PE_BOOLS = ['enabled', 'debug', 'notify_remember', 'notify_kept'];
const PE_NUMBERS = [
    'initiative_chance' => [0, 100],
    'global_cooldown_minutes' => [0, 1440],
    'npc_cooldown_minutes' => [0, 1440],
    'quiet_after_combat_seconds' => [0, 3600],
    'offer_expiry_minutes' => [1, 120],
    'max_open_per_npc' => [0, 50],
    'weight_aspiration' => [0, 100],
    'weight_activity' => [0, 100],
    'weight_grudge' => [0, 100],
    'weight_relationship' => [0, 100],
    'weight_curiosity' => [0, 100],
    'promise_expiry_days' => [0, 3650],
];
const PE_STATUSES = ['open' => 'Open', 'offered' => 'Waiting for your answer', 'kept' => 'Kept', 'declined' => 'Declined',
    'expired' => 'Not answered', 'cancelled' => 'Cancelled',
    'impossible' => 'No longer possible', 'lapsed' => 'Forgotten (too old)'];

if (isset($_GET['download'])) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="promise-engine-diagnostics-' . gmdate('Ymd-His') . '.txt"');
    header('Cache-Control: no-store');
    echo cpeDiagnosticReport();
    exit;
}

$message = '';
$error = '';
$dbReady = cpeDbReady();
$selected = isset($_GET['pt']) && ctype_digit((string)$_GET['pt']) ? (int)$_GET['pt'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');
    $id = (int)($_POST['id'] ?? 0);
    if (!$dbReady) {
        $error = 'The plugin tables are missing. Reinstall the plugin so its migrations run.';
    } elseif ($action === 'save') {
        $ok = true;
        foreach (PE_BOOLS as $key) {
            $ok = cpeSet($key, isset($_POST[$key]) ? '1' : '0') && $ok;
        }
        foreach (PE_NUMBERS as $key => [$min, $max]) {
            $value = is_numeric($_POST[$key] ?? null) ? (int)$_POST[$key] : (int)cpeDefaults()[$key];
            $ok = cpeSet($key, (string)max($min, min($max, $value))) && $ok;
        }
        $ok ? $message = 'Settings saved. They apply to the next request.' : $error = 'Some settings could not be saved.';
        cpeLog('info', 'Settings changed on the plugin page');
    } elseif ($action === 'restore_defaults') {
        foreach (array_merge(PE_BOOLS, array_keys(PE_NUMBERS)) as $key) {
            cpeSet($key, cpeDefaults()[$key]);
        }
        $message = 'All settings restored to their defaults.';
    } elseif ($action === 'reset_cooldown') {
        cpeSet('last_initiative_ts', '0');
        cpeOne('UPDATE ' . CPE_INITIATIVES . ' SET created_ts = 0 RETURNING id');
        $message = 'Cooldowns reset. The next quiet moment can be used right away.';
    } elseif ($action === 'follow_auto') {
        cpeSet('playthrough_override', '');
        $message = 'New promises follow CHIM\'s active playthrough again.';
    } elseif ($action === 'use_playthrough') {
        cpeSet('playthrough_override', (string)$id);
        $message = 'New promises are now saved to this tab, whatever CHIM\'s active playthrough is.';
    }
    if ($dbReady && in_array($action, ['mark_kept', 'reopen', 'cancel', 'delete', 'edit'], true)) {
        $peRow = cpeOne('SELECT npc, title FROM ' . CPE_PROMISES . ' WHERE id = $1', [$id]);
        cpeLog('info', 'Plugin page: ' . str_replace('_', ' ', $action) . ' #' . $id . ($peRow ? " ({$peRow['npc']} - {$peRow['title']})" : '') . ', by hand');
    }
    if (!$dbReady) {
        // nothing
    } elseif ($action === 'mark_kept') {
        cpeOne('UPDATE ' . CPE_PROMISES . " SET status = 'kept', resolved_at = CURRENT_TIMESTAMP WHERE id = $1 RETURNING id", [$id]);
        $message = 'Promise marked as kept (no reaction in game).';
    } elseif ($action === 'reopen') {
        cpeOne('UPDATE ' . CPE_PROMISES . " SET status = 'open', resolved_at = NULL WHERE id = $1 RETURNING id", [$id]);
        $message = 'Promise opened again.';
    } elseif ($action === 'cancel') {
        cpeOne('UPDATE ' . CPE_PROMISES . " SET status = 'cancelled', resolved_at = CURRENT_TIMESTAMP WHERE id = $1 RETURNING id", [$id]);
        $message = 'Promise cancelled.';
    } elseif ($action === 'delete') {
        cpeOne('DELETE FROM ' . CPE_PROMISES . ' WHERE id = $1 RETURNING id', [$id]);
        $message = 'Promise deleted.';
    } elseif ($action === 'edit') {
        $old = cpeOne('SELECT kind, target FROM ' . CPE_PROMISES . ' WHERE id = $1', [$id]);
        $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 200);
        $place = mb_substr(trim((string)($_POST['place'] ?? '')), 0, 200);
        $target = ($old['kind'] ?? '') === 'kill' ? mb_substr(trim((string)($_POST['target'] ?? '')), 0, 200) : (string)($old['target'] ?? '');
        $kind = ($old['kind'] ?? 'free') === 'kill' ? 'kill' : ($place !== '' ? 'place' : 'free');
        cpeOne('UPDATE ' . CPE_PROMISES . ' SET title = $1, place = $2, target = $3, kind = $4, subject = $5 WHERE id = $6 RETURNING id',
            [$title, $place, $target, $kind, cpeSubject($kind, $target, $place, $title), $id]);
        $message = 'Promise updated.';
    } elseif ($action === 'clear_log') {
        cpeOne('DELETE FROM ' . CPE_LOG . ' RETURNING 1');
        $message = 'Diagnostics log cleared.';
    }
}

$settings = cpeSettings(true);
$current = cpeCurrentPlaythrough($settings);
$playthroughs = cpePlaythroughs();
if ($selected === null || !isset($playthroughs[$selected])) {
    $selected = $current['id'];
}
$showCompleted = ($_GET['completed'] ?? '') === '1';
$showFailed = ($_GET['failed'] ?? '') === '1';
$allPromises = $dbReady ? cpePromises($selected) : [];
$promises = array_values(array_filter($allPromises, static function ($p) use ($showCompleted, $showFailed) {
    if (in_array($p['status'], ['open', 'offered'], true)) {
        return true;
    }
    return $p['status'] === 'kept' ? $showCompleted : $showFailed;
}));
$hiddenCount = count($allPromises) - count($promises);
/** Query string that keeps the selected tab and filters. */
$peQs = '?pt=' . (int)$selected . ($showCompleted ? '&completed=1' : '') . ($showFailed ? '&failed=1' : '');
$initiatives = $dbReady ? cpeRecentInitiatives($selected, 20) : [];
$party = cpeParty();
$lastTs = (int)$settings['last_initiative_ts'];
$cooldownLeft = max(0, $lastTs + (int)$settings['global_cooldown_minutes'] * 60 - time());
$logRows = cpeLogFetch(150);
$boredOn = (int)($GLOBALS['BORED_EVENT'] ?? 0);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CHIM - Promise Engine</title>
<style>
  :root { color-scheme: dark; --bg:#12110f; --panel:#1c1a16; --line:#38322a; --text:#ece6da; --muted:#a69c8c; --accent:#c8963e; --ok:#7aa35f; --bad:#d76b5a; }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--bg); color:var(--text); font:15px/1.5 system-ui, sans-serif; }
  main { max-width: 980px; margin: 0 auto; padding: 24px 16px 48px; }
  h1 { margin:0 0 4px; font-size: 26px; letter-spacing:.5px; }
  h1 span { color: var(--accent); }
  .sub { color: var(--muted); margin: 0 0 20px; }
  section { background:var(--panel); border:1px solid var(--line); border-radius:10px; padding:18px; margin-bottom:16px; }
  h2 { font-size:16px; margin:0 0 12px; }
  label { display:block; margin: 12px 0 4px; font-weight:600; }
  label.check { font-weight:500; }
  .hint { color:var(--muted); font-size:13px; margin:2px 0 0; }
  .def { color:var(--muted); font-size:12px; font-weight:normal; white-space:nowrap; }
  input[type=number] { width: 110px; }
  input, select { background:#0e0d0b; color:var(--text); border:1px solid var(--line); border-radius:6px; padding:7px; font:inherit; }
  .row { display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 8px 16px; }
  button, .button { background:var(--accent); color:#16120b; border:0; border-radius:6px; padding:8px 14px; font:inherit; font-weight:600; cursor:pointer; text-decoration:none; display:inline-block; }
  button.secondary, .button.secondary { background:transparent; border:1px solid var(--line); color:var(--text); font-weight:500; }
  button.small { padding:4px 9px; font-size:13px; }
  .msg { padding:10px 12px; border-radius:6px; margin-bottom:16px; }
  .ok { background:#1d2819; border:1px solid var(--ok); }
  .err { background:#2a1715; border:1px solid var(--bad); }
  table { border-collapse: collapse; width:100%; }
  td, th { padding:7px 5px; border-bottom:1px solid var(--line); vertical-align: top; text-align:left; }
  th { color:var(--muted); font-weight:600; font-size:13px; }
  table.kv td:first-child { color:var(--muted); width:40%; }
  .inline { display:flex; gap:8px; flex-wrap:wrap; margin-top:14px; align-items:center; }
  .good { color:#97c47d; } .bad { color:var(--bad); } .muted { color:var(--muted); }
  .tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:14px; }
  .tab { padding:7px 12px; border:1px solid var(--line); border-radius:999px; color:var(--text); text-decoration:none; font-size:14px; }
  .tab.sel { background:var(--accent); color:#16120b; border-color:var(--accent); font-weight:600; }
  .badge { display:inline-block; font-size:11px; padding:1px 7px; border-radius:999px; border:1px solid var(--line); color:var(--muted); margin-left:4px; }
  .st-open { color:var(--accent); } .st-kept { color:#97c47d; } .st-offered { color:#9fb6d6; }
  .quote { color:var(--muted); font-size:13px; font-style:italic; }
  details summary { cursor:pointer; color:var(--muted); font-size:13px; }
  .log { background:#0e0d0b; border:1px solid var(--line); border-radius:6px; padding:8px; max-height:380px; overflow:auto; font:12px/1.45 ui-monospace, Consolas, monospace; white-space:pre-wrap; word-break:break-word; }
  .log .warn { color:#e3b65c; } .log .error { color:var(--bad); } .log .debug { color:var(--muted); }
  table.weights td { vertical-align: middle; } table.weights input { width: 80px; }
  .sticky { position: sticky; bottom: 0; background: var(--bg); padding: 10px 0; }
  form.inline-form { display:inline; }
</style>
</head>
<body>
<main>
  <h1>CHIM - <span>Promise Engine</span></h1>
  <p class="sub">Followers take the initiative, make plans with you and remember your promises. v<?= peH(CPE_VERSION) ?></p>

  <?php if ($message): ?><div class="msg ok"><?= peH($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="msg err"><?= peH($error) ?></div><?php endif; ?>
  <?php if (!$dbReady): ?><div class="msg err">Plugin database tables are missing. Defaults are used and nothing is saved until the plugin is reinstalled.</div><?php endif; ?>

  <section>
    <h2>Status</h2>
    <table class="kv">
      <tr><td>Promise Engine</td><td><?= cpeOn($settings, 'enabled') ? '<span class="good">on</span>' : '<span class="bad">off</span>' ?></td></tr>
      <tr><td>New promises are saved to</td><td><?= peH($current['name']) ?>
        <?= $current['source'] === 'auto' ? '<span class="badge">follows CHIM automatically</span>' : '<span class="badge">chosen by hand</span>' ?></td></tr>
      <tr><td>Followers in the party now</td><td><?= $party ? peH(implode(', ', $party)) : '<span class="muted">none</span>' ?></td></tr>
      <tr><td>CHIM "Bored event" chance</td><td><?= (int)$boredOn ?> % <span class="muted">(CHIM's own setting; Promise Engine decides before it. If followers never speak up, check that the game sends bored events at all.)</span></td></tr>
      <tr><td>Initiatives / proposals / agreed / declined / kept</td><td><?= (int)$settings['stat_initiatives'] ?> / <?= (int)$settings['stat_offers'] ?> / <?= (int)$settings['stat_agreed'] ?> / <?= (int)$settings['stat_declined'] ?> / <?= (int)$settings['stat_kept'] ?></td></tr>
      <tr><td>Text added to the LLM request: last initiative / last agreement check</td><td>~<?= (int)$settings['tokens_last_initiative'] ?> / ~<?= (int)$settings['tokens_last_check'] ?> tokens <span class="muted">(rough estimate; the initiative replaces CHIM's own bored line, the check is a separate small request)</span></td></tr>
      <tr><td>Last initiative / cooldown left</td><td><?= $lastTs > 0 ? peH(date('Y-m-d H:i:s', $lastTs)) : 'never' ?> / <?= $cooldownLeft > 0 ? peH(ceil($cooldownLeft / 60) . ' min') : 'none' ?></td></tr>
    </table>
  </section>

  <section>
    <h2>Promises</h2>
    <p class="hint">One tab per CHIM playthrough. The tab marked <em>active</em> is CHIM's current playthrough; new promises go there unless you choose another tab by hand.</p>
    <div class="tabs">
      <?php foreach ($playthroughs as $pt): ?>
        <a class="tab <?= $pt['id'] === $selected ? 'sel' : '' ?>" href="?pt=<?= (int)$pt['id'] ?><?= $showCompleted ? '&amp;completed=1' : '' ?><?= $showFailed ? '&amp;failed=1' : '' ?>"><?= peH($pt['name']) ?><?= $pt['active'] ? ' · active' : '' ?><?= $pt['id'] === $current['id'] ? ' ★' : '' ?></a>
      <?php endforeach; ?>
    </div>
    <div class="inline" style="margin-top:0;margin-bottom:12px">
      <?php if ($selected !== $current['id'] || $current['source'] === 'auto'): ?>
        <form method="post" class="inline-form" action="<?= peH($peQs) ?>"><input type="hidden" name="id" value="<?= (int)$selected ?>">
          <button class="secondary small" name="action" value="use_playthrough">Save new promises to this tab</button></form>
      <?php endif; ?>
      <?php if ($current['source'] === 'manual'): ?>
        <form method="post" class="inline-form" action="<?= peH($peQs) ?>"><button class="secondary small" name="action" value="follow_auto">Follow CHIM's active playthrough</button></form>
      <?php endif; ?>
      <span class="muted">★ = new promises go here</span>
    </div>
    <form method="get" class="inline" style="margin:0 0 10px">
      <input type="hidden" name="pt" value="<?= (int)$selected ?>">
      <label class="check" style="margin:0"><input type="checkbox" name="completed" value="1" <?= $showCompleted ? 'checked' : '' ?> onchange="this.form.submit()"> Show completed</label>
      <label class="check" style="margin:0"><input type="checkbox" name="failed" value="1" <?= $showFailed ? 'checked' : '' ?> onchange="this.form.submit()"> Show failed</label>
      <?php if ($hiddenCount > 0): ?><span class="muted"><?= (int)$hiddenCount ?> hidden</span><?php endif; ?>
    </form>
    <p class="hint" style="margin-top:-4px">Open promises and proposals waiting for your answer are always shown. Completed = kept. Failed = no longer possible, forgotten, declined, not answered or cancelled.</p>

    <?php if (!$promises): ?>
      <p class="muted"><?= $allPromises ? 'Nothing to show with these filters.' : 'No promises in this playthrough yet. When a follower suggests something and you agree, it appears here.' ?></p>
    <?php else: ?>
    <table>
      <tr><th>Status</th><th>Follower</th><th>Promise</th><th>Place / target</th><th>Made</th><th></th></tr>
      <?php foreach ($promises as $p): ?>
      <tr>
        <td class="st-<?= peH($p['status']) ?>"><?= peH(PE_STATUSES[$p['status']] ?? $p['status']) ?></td>
        <td><?= peH($p['npc']) ?></td>
        <td><?= $p['title'] !== '' ? peH($p['title']) : '<span class="muted">–</span>' ?>
          <?php if ($p['offer_text'] !== ''): ?><div class="quote">“<?= peH(mb_substr($p['offer_text'], 0, 240)) ?>”</div><?php endif; ?>
          <?php if ($p['player_answer'] !== ''): ?><div class="quote">You: “<?= peH(mb_substr($p['player_answer'], 0, 200)) ?>”</div><?php endif; ?>
          <?php if ($p['reaction_text'] !== ''): ?><div class="quote">Kept: “<?= peH(mb_substr($p['reaction_text'], 0, 240)) ?>”</div><?php endif; ?>
          <?php if (in_array($p['status'], ['open', 'kept'], true)): ?>
          <details><summary>edit</summary>
            <form method="post" action="<?= peH($peQs) ?>"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <label>Promise</label><input name="title" value="<?= peH($p['title']) ?>" style="width:100%">
              <?php if ($p['kind'] === 'kill'): ?>
              <label>Target (the promise is kept when they die by your or your group's hand)</label><input name="target" value="<?= peH($p['target']) ?>" style="width:100%">
              <input type="hidden" name="place" value="<?= peH($p['place']) ?>">
              <?php else: ?>
              <label>Place (the promise is kept when you arrive there with the follower)</label><input name="place" value="<?= peH($p['place']) ?>" style="width:100%">
              <?php endif; ?>
              <div class="inline"><button class="small" name="action" value="edit">Save</button></div>
            </form>
          </details>
          <?php endif; ?>
        </td>
        <td><?php if ($p['kind'] === 'kill'): ?>Target: <?= peH($p['target']) ?><?php elseif ($p['place'] !== ''): ?><?= peH($p['place']) ?><?php else: ?><span class="muted">none</span><?php endif; ?>
          <?php if (($p['is_unique'] ?? 'f') === 't'): ?><span class="badge">unique</span><?php endif; ?>
          <?php if (($p['close_reason'] ?? '') !== ''): ?><div class="quote"><?= peH($p['close_reason'] === 'react_pending' ? 'the follower reacts at the next quiet moment' : $p['close_reason']) ?></div><?php endif; ?></td>
        <td><?= peH(cpeGameDate($p['agreed_gamets'] ?: $p['created_gamets']) ?: substr((string)$p['created_at'], 0, 16)) ?></td>
        <td style="white-space:nowrap">
          <form method="post" class="inline-form" action="<?= peH($peQs) ?>"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <?php if ($p['status'] === 'open'): ?>
              <button class="secondary small" name="action" value="mark_kept">Kept</button>
              <button class="secondary small" name="action" value="cancel">Cancel</button>
            <?php elseif (in_array($p['status'], ['kept', 'cancelled'], true)): ?>
              <button class="secondary small" name="action" value="reopen">Reopen</button>
            <?php endif; ?>
            <button class="secondary small" name="action" value="delete" onclick="return confirm('Delete this promise?');">Delete</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </section>

  <form method="post" action="<?= peH($peQs) ?>">
    <section>
      <h2>General</h2>
      <label class="check"><input type="checkbox" name="enabled" value="1" <?= peChecked($settings, 'enabled') ?>> Promise Engine enabled<?= peDefOnOff('enabled') ?></label>
      <label class="check"><input type="checkbox" name="debug" value="1" <?= peChecked($settings, 'debug') ?>> Debug log<?= peDefOnOff('debug') ?></label>
      <p class="hint">Writes every decision (skipped, cooldown, roll) to the log below.</p>
    </section>

    <section>
      <h2>Initiatives</h2>
      <p class="hint">CHIM sends a "bored" event when nobody has spoken for a while. Promise Engine uses some of these quiet moments: a follower speaks up on their own instead of CHIM's usual line. Silence is intentional, so keep the chance low.</p>
      <div class="row">
        <div><label>Chance (%)<?= peDef('initiative_chance', ' %') ?></label><input type="number" name="initiative_chance" min="0" max="100" value="<?= peH($settings['initiative_chance']) ?>"><p class="hint">Of CHIM's quiet moments, how many a follower uses.</p></div>
        <div><label>Cooldown, any follower (real minutes)<?= peDef('global_cooldown_minutes', ' min') ?></label><input type="number" name="global_cooldown_minutes" min="0" max="1440" value="<?= peH($settings['global_cooldown_minutes']) ?>"></div>
        <div><label>Cooldown, same follower (real minutes)<?= peDef('npc_cooldown_minutes', ' min') ?></label><input type="number" name="npc_cooldown_minutes" min="0" max="1440" value="<?= peH($settings['npc_cooldown_minutes']) ?>"><p class="hint">Initiatives are spread across the party: the follower who spoke up longest ago goes first.</p></div>
        <div><label>Quiet after combat (s)<?= peDef('quiet_after_combat_seconds', ' s') ?></label><input type="number" name="quiet_after_combat_seconds" min="0" max="3600" value="<?= peH($settings['quiet_after_combat_seconds']) ?>"><p class="hint">No initiatives during or right after a fight.</p></div>
      </div>
      <label>What followers bring up, and how often</label>
      <p class="hint">Weights, not percentages: each kind's share is its weight divided by the sum of all weights. 0 = never. The share is updated after saving.</p>
      <table class="weights">
        <tr><th>Kind</th><th>Weight</th><th>Share</th><th>Default</th></tr>
        <tr><td><b>Aspiration</b>: a personal wish, and an invitation to do it together</td><td><input type="number" name="weight_aspiration" min="0" max="100" value="<?= peH($settings['weight_aspiration']) ?>"></td><td><?= peShare($settings, 'aspiration') ?></td><td class="def"><?= peH(cpeDefaults()['weight_aspiration']) ?></td></tr>
        <tr><td><b>Activity</b>: something to do together soon, somewhere near</td><td><input type="number" name="weight_activity" min="0" max="100" value="<?= peH($settings['weight_activity']) ?>"></td><td><?= peShare($settings, 'activity') ?></td><td class="def"><?= peH(cpeDefaults()['weight_activity']) ?></td></tr>
        <tr><td><b>Grudge</b>: a named person they want dead, and a request for your help</td><td><input type="number" name="weight_grudge" min="0" max="100" value="<?= peH($settings['weight_grudge']) ?>"></td><td><?= peShare($settings, 'grudge') ?></td><td class="def"><?= peH(cpeDefaults()['weight_grudge']) ?></td></tr>
        <tr><td><b>Relationship</b>: a personal thought about you or a companion</td><td><input type="number" name="weight_relationship" min="0" max="100" value="<?= peH($settings['weight_relationship']) ?>"></td><td><?= peShare($settings, 'relationship') ?></td><td class="def"><?= peH(cpeDefaults()['weight_relationship']) ?></td></tr>
        <tr><td><b>Curiosity</b>: something here that catches their interest</td><td><input type="number" name="weight_curiosity" min="0" max="100" value="<?= peH($settings['weight_curiosity']) ?>"></td><td><?= peShare($settings, 'curiosity') ?></td><td class="def"><?= peH(cpeDefaults()['weight_curiosity']) ?></td></tr>
      </table>
      <p class="hint">Aspiration, Activity and Grudge end with a proposal. Agree in your own words and it becomes a promise; Relationship and Curiosity are just talk.</p>
    </section>

    <section>
      <h2>Promises</h2>
      <div class="row">
        <div><label>Time to answer a proposal (real minutes)<?= peDef('offer_expiry_minutes', ' min') ?></label><input type="number" name="offer_expiry_minutes" min="1" max="120" value="<?= peH($settings['offer_expiry_minutes']) ?>"><p class="hint">After this, the proposal is dropped. Your next two lines are checked.</p></div>
        <div><label>Forget open promises after (game days)<?= peDef('promise_expiry_days', ' days') ?></label><input type="number" name="promise_expiry_days" min="0" max="3650" value="<?= peH($settings['promise_expiry_days']) ?>"><p class="hint">An open promise older than this is quietly forgotten. 0 = never.</p></div>
        <div><label>Open promises per follower<?= peDef('max_open_per_npc', '') ?></label><input type="number" name="max_open_per_npc" min="0" max="50" value="<?= peH($settings['max_open_per_npc']) ?>"><p class="hint">With this many open promises, the follower stops proposing new ones.</p></div>
      </div>
      <label class="check"><input type="checkbox" name="notify_remember" value="1" <?= peChecked($settings, 'notify_remember') ?>> Show "&lt;Follower&gt; will remember this." in game when you agree<?= peDefOnOff('notify_remember') ?></label>
      <label class="check"><input type="checkbox" name="notify_kept" value="1" <?= peChecked($settings, 'notify_kept') ?>> Show "Promise kept: ..." in game<?= peDefOnOff('notify_kept') ?></label>
      <p class="hint">Like CHIM itself, Promise Engine forgets what happened after the moment you load: if you load an older save (or die and go back to the last save), promises made later are gone and promises kept later are open again.</p>
      <p class="hint">A promise to kill someone is kept when they die by your or your group's hand. If someone else kills them first, it can no longer be kept, and the follower reacts to that. A unique promise (a killing, a first visit) is never offered or stored twice; people who are already dead are not suggested.</p>
      <p class="hint">A promise with a place is kept when you arrive there with that follower in the party; the follower reacts at the next quiet moment. Promises without a place can be closed here by hand.</p>
    </section>

    <div class="inline sticky">
      <button type="submit" name="action" value="save">Save settings</button>
      <button type="submit" name="action" value="reset_cooldown" class="secondary">Reset cooldowns</button>
      <button type="submit" name="action" value="restore_defaults" class="secondary" onclick="return confirm('Restore all settings to their defaults?');">Restore all defaults</button>
    </div>
  </form>

  <section>
    <h2>Recent initiatives (this tab)</h2>
    <?php if (!$initiatives): ?><p class="muted">None yet.</p><?php else: ?>
    <table>
      <tr><th>When</th><th>Follower</th><th>Lens</th><th>What they said</th></tr>
      <?php foreach ($initiatives as $i): ?>
      <tr><td style="white-space:nowrap"><?= peH(substr((string)$i['created_at'], 0, 16)) ?></td><td><?= peH($i['npc']) ?></td><td><?= peH($i['lens']) ?></td>
        <td><?= $i['spoken'] !== '' ? peH($i['spoken']) : '<span class="muted">(no reply recorded)</span>' ?></td></tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </section>

  <section>
    <h2>Diagnostics</h2>
    <p class="hint">If something does not work, create a diagnostic file and send it with your report. It contains the version, settings, promises of the current playthrough and the log (no API keys).</p>
    <div class="inline">
      <a class="button" href="?download=1">Create diagnostic file</a>
      <form method="post" class="inline-form"><button type="submit" name="action" value="clear_log" class="secondary">Clear log</button></form>
    </div>
    <div class="log" style="margin-top:12px"><?php if (!$logRows): ?><span class="muted">No log entries yet.</span><?php endif; ?><?php foreach ($logRows as $row): ?><div class="<?= peH($row['level']) ?>"><?= peH(substr((string)$row['created_at'], 0, 19) . ' ' . $row['message']) ?></div><?php endforeach; ?></div>
  </section>
</main>
</body>
</html>

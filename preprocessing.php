<?php
/**
 * CHIM - Promise Engine: HerikaServer hook (main.php, before CHIM routes the request).
 *
 * - "bored": a follower may take the initiative (or react to a kept promise) instead of CHIM's own bored line.
 *   The request becomes a CHIM "suggestion" for that follower's profile.
 * - "location" / "infoloc" / player input: arrival at a promised place is checked; the follower reacts within seconds.
 * - "death": kill promises are resolved right after CHIM stored the death line.
 * - Player input: the line is remembered so postrequest.php can check it against a pending offer.
 * - "init" (a save was loaded) / "playerdied": after CHIM pruned its own history, promises made after
 *   the loaded moment are forgotten too (skipped when CHIM skips its rollback).
 * Everything else is left untouched.
 */

$cpeType = strtolower((string)($gameRequest[0] ?? ''));

if (in_array($cpeType, ['location', 'infoloc', 'inputtext', 'inputtext_s', 'ginputtext', 'ginputtext_s'], true)) {
    if ($cpeType !== 'location' && $cpeType !== 'infoloc') {
        $GLOBALS['CPE_PLAYER_TEXT'] = (string)($gameRequest[3] ?? '');
    }
    // Arrival at a promised place is checked on every location update, not only in quiet moments.
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'promise_engine.php';
    try {
        cpeCheckArrival($gameRequest);
    } catch (Throwable $e) {
        cpeLog('error', 'Arrival check failed: ' . $e->getMessage());
    }
    return;
}
if ($cpeType === 'death') {
    // Runs after CHIM stored the death line, so kill promises resolve right away.
    $cpeDeathTs = (int)($gameRequest[2] ?? 0);
    register_shutdown_function(static function () use ($cpeDeathTs): void {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'promise_engine.php';
        try {
            cpeHandleDeathEvent($cpeDeathTs);
        } catch (Throwable $e) {
            cpeLog('error', 'Death check failed: ' . $e->getMessage());
        }
    });
    return;
}
if ($cpeType === 'init' || $cpeType === 'playerdied') {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'promise_engine.php';
    $cpeLoaded = $cpeType === 'init' ? (int)($gameRequest[2] ?? 0) : cpeLastSaveGamets();
    if ($cpeLoaded > 0 && $cpeLoaded !== 10000000) {
        // Runs after CHIM's own handler (processor/comm.php) has finished the request.
        register_shutdown_function(static function () use ($cpeLoaded, $cpeType): void {
            if (!empty($GLOBALS['pgr_skip_rollback'])) {
                cpeLog('warn', 'CHIM skipped its rollback, so promises are kept as they are');
                return;
            }
            try {
                cpeRollback($cpeLoaded, $cpeType === 'init' ? 'Save loaded' : 'Player died');
            } catch (Throwable $e) {
                cpeLog('error', 'Rollback failed: ' . $e->getMessage());
            }
        });
    }
    return;
}
if ($cpeType !== 'bored') {
    return;
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'promise_engine.php';

try {
    $cpePlan = cpeHandleBored($gameRequest);
} catch (Throwable $e) {
    $cpePlan = null;
    cpeLog('error', 'Bored handler failed: ' . $e->getMessage());
}
if ($cpePlan === null) {
    return;
}

$GLOBALS['CPE_PLAN'] = $cpePlan;
$_GET['profile'] = $cpePlan['profile'];
$gameRequest[0] = 'suggestion';
$gameRequest[3] = $cpePlan['text'];
$gameRequest[4] = $cpePlan['npc'];

<?php
/**
 * CHIM - Promise Engine: HerikaServer hook (end of main.php, after the reply was sent to the game).
 *
 * - After a Promise Engine line: store what the follower said (a proposal becomes an offer).
 * - After a player line: if an offer is waiting, check whether the player agreed.
 */

if (empty($GLOBALS['CPE_PLAN']) && !isset($GLOBALS['CPE_PLAYER_TEXT'])) {
    return;
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'promise_engine.php';

try {
    $cpeSpoken = implode(' ', array_map('strval', (array)($GLOBALS['talkedSoFar'] ?? [])));
    if (!empty($GLOBALS['CPE_PLAN'])) {
        cpeAfterSpeech($GLOBALS['CPE_PLAN'], $cpeSpoken);
    } else {
        cpeHandlePlayerReply((string)$GLOBALS['CPE_PLAYER_TEXT'], (string)($GLOBALS['HERIKA_NAME'] ?? ''), $cpeSpoken, (int)($gameRequest[2] ?? 0));
    }
} catch (Throwable $e) {
    cpeLog('error', 'Post-request handler failed: ' . $e->getMessage());
}

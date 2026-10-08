# Changelog

## 0.5.0

First public version: Phase 1 (initiatives and promises). Feedback welcome: open an issue and attach the diagnostic file from the plugin page.

- Followers take the initiative in CHIM's quiet moments (Aspiration, Activity, Grudge, Relationship, Curiosity).
- Proposals become promises when you agree; "<Follower> will remember this." in game.
- Promises with a place are kept when you arrive there with the follower; the follower reacts and the game shows "Promise kept: …".
- Loading an older save (or dying) forgets promises made after that moment, like CHIM forgets the conversation.
- Weights on the plugin page decide how often each kind of initiative is picked (default 30/25/10/20/15).
- Proposals stay in Skyrim and Solstheim; a promise about a person or place in another province is not stored.
- After a save load, followers forget what they brought up, but turn-taking stays fair (the follower who spoke up longest ago goes first).
- Arrival at a promised place is checked on every location update (any name in CHIM's location context counts). The follower reacts within seconds (queued like CHIM's director suggestions), as does a kill promise right after the death. Location changes are logged while a place promise is open.
- Promise kinds: place (kept on arrival), kill (kept when the target dies by your group's hand; can no longer be kept if someone else kills them), free (closed by hand).
- Unique promises are never offered or stored twice; people already dead are not suggested; a follower's death closes their promises; open promises are forgotten after a number of game days.
- Agreement check loads each connector's own settings before calling it (another CHIM step could switch them, so every connector answered nothing); without an LLM answer only a clear "yes" counts and nothing is declined.
- Declined or cancelled proposals are remembered and not proposed again soon (any follower); death lines like "Sofia has defeated Sinding(powerful enemy) using weapon Steel Sword" are read cleanly.
- Grudge names exactly one living person; an essential target is noted; NPCs CHIM marks as dead are hinted and refused.
- Agreement check tries the NPC's connector, then CHIM's medium-term and scene classifier connectors, and logs why when none answers; the keyword fallback picks a better title.
- Plugin page: promise list shows open promises by default, with "Show completed" and "Show failed" filters; manual actions are logged.
- Plugin page: one tab per CHIM playthrough, promise list with edit / kept / cancel / delete, settings, recent initiatives, diagnostics.

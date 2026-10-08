# CHIM - Promise Engine – Roadmap

Followers that take the initiative, make promises with you, and remember them.

## The end result

You travel with Serana. Now and then, when nothing is happening, she speaks up on her own,
not with small talk but with something that comes from who she is:

> "When this is over, I want to sit in the Bee and Barb in Riften and drink something that isn't blood-wine."

You answer "Sure, let's do that." A notification appears at the top of the screen:

> **Serana will remember this.**

The wish is now a promise, stored like a private quest. Days later you walk into the Bee and Barb with her.
The screen shows **Promise kept: Share a drink with Serana at the Bee and Barb**, and she reacts in her own words.

Over time it adds up: promises you keep build trust, promises you forget or break leave a mark,
and Serana's CHIM profile, and the way she talks to you, changes with them.

## Design rules

- **Server-only plugin.** No SKSE DLL, no ESP. Installs through the CHIM Plugin Manager. Phase 2 stays server-only too (CHIM profiles live on the server).
- **Silence is intentional.** Initiatives are rare, never during combat, spread across the party, with cooldowns.
- **Cheap.** An initiative replaces CHIM's own bored line (~100–230 extra tokens). One small check (~450 tokens) only when the player answers a proposal. Arrival, deaths and saves are database checks, no LLM.
- **Per playthrough.** Promises belong to the active CHIM playthrough. Switching saves switches the list.
- **Every extra behaviour is a setting** on the plugin page, with a short explanation.

## Phase 1 – Initiatives and promises (v0.5, current)

**Initiatives**

- A share of CHIM's `bored` events goes to party followers. Chance, cooldowns and weights are set on the plugin page.
- Five lenses, picked by weight: Aspiration, Activity, Grudge (end with a proposal), Relationship, Curiosity (just talk).
- No initiatives during or right after combat; the follower who spoke up longest ago goes first.
- Proposals stay in Skyrim and Solstheim.

**Promises**

- A proposal waits for the player's answer; a short LLM check decides agreed / declined / unclear.
- Agreed: the promise is stored and the game shows "<Follower> will remember this."
- Kinds: place (kept on arrival), kill (kept when the target dies by the group's hand), free (closed by hand).
- Unique promises are never offered or stored twice; dead people and declined proposals are not suggested again.
- The follower gets a short "not this" list (at most 8 entries) to keep the prompt small.

**Fulfilment**

- Arrival is checked on every location update; the follower reacts within seconds.
- A kill promise resolves right after the death: kept (the group did it) or no longer possible (someone else did).
- If the follower dies, their open promises close. Old open promises are forgotten after a set number of game days.

**Saves**

- Like CHIM itself: loading an older save (or dying) forgets promises made after that moment; promises kept after it are open again.

**Plugin page**

- One tab per playthrough, following CHIM's active one (manual override possible).
- Open promises by default, "Show completed" / "Show failed" filters; edit, kept, cancel, delete.
- Settings, recent initiatives, diagnostic file.

## Phase 2 – Promises shape the follower

Kept and broken promises become part of who the follower is.

- **Profile and relationship.** Kept and broken promises are written into the follower's CHIM profile, so they shape how the follower treats you from then on (a setting; off keeps the profile untouched).
- **Broken promises.** Forgotten, failed or abandoned promises count as broken, and the follower may bring them up.
- **Reminders.** A follower may remind you of an old open promise instead of proposing a new one.
- **A fallen follower is remembered.** When a follower dies, the others remember the promises left unkept.

## Later ideas (not scheduled)

- Promises with a deadline ("before the next full moon").
- Protect someone ("keep my sister alive").
- Items, quests and shouts as promise goals.
- Count promises ("kill five bandits").
- Conflicting promises between followers.
- Gifts and small rewards for kept promises.
- Follower-to-follower promises and multi-step stories.
- Promises kept in conversation, and promises the player makes first.

---
Made by Marcius3d. And Santa's little helper Claude.

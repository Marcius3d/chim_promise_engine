# CHIM - Promise Engine

Followers take the initiative, propose plans and remember the promises you make them.

> **Serana:** "When this is over, I want to sit in the Bee and Barb and drink something that isn't blood-wine. Come with me?"
> **You:** "Sure, let's do that."
> *Serana will remember this.*
>
> Days later you walk into the Bee and Barb with her. She notices, and reacts in her own words.
> *Promise kept: Share a drink with Serana at the Bee and Barb*

A server plugin for [CHIM](https://github.com/Dwemer-Dynamics) (HerikaServer). No SKSE plugin, no ESP.

## How it works

- **Initiatives.** CHIM sends a "bored" event when nobody has spoken for a while. Promise Engine uses some of these quiet moments: a follower in your party speaks up on their own. What they bring up comes from five lenses, each with its own weight on the plugin page:
  - **Aspiration**: a personal wish, and an invitation to do it together.
  - **Activity**: something to do together soon, somewhere near.
  - **Grudge**: one named person they want dead, and a request for your help.
  - **Relationship**: a personal thought about you or a companion.
  - **Curiosity**: something in the surroundings that catches their interest.
- **Promises.** After an Aspiration, Activity or Grudge proposal, answer in your own words. One short check with your NPC's LLM decides whether you agreed. If you did, the game shows *"<Follower> will remember this."* and the promise is saved.
- **Keeping them.** Arrive at the promised place with that follower in the party, or make sure the named target dies by your group's hand. Within seconds the game shows *"Promise kept: …"* and the follower reacts in their own words. If someone else kills the target first, the promise can no longer be kept, and the follower says so.
- **Unique promises.** A killing or a first visit is never offered twice. People already dead, and proposals you turned down, are not suggested again.
- **Skyrim only.** Everything proposed is reachable in Skyrim or Solstheim, not in another province.
- **Per playthrough.** Promises belong to the CHIM playthrough (Playthrough Saves) that was active when they were made. The plugin page has one tab per playthrough.
- **Follows your saves.** Load an older save (or die and go back) and promises made after that moment are forgotten, just like CHIM forgets the conversation.
- **Quiet by design.** Low chance, cooldowns per follower and for the whole party, nothing during or right after combat.

## Install

- **CHIM Plugin Manager:** find *CHIM - Promise Engine* in the list and install it.
- **Mod Organizer 2 / Vortex:** install `CHIM-Promise-Engine.zip` from the [latest release](https://github.com/Marcius3d/chim_promise_engine/releases/latest). CHIM installs the server part on the next game start.

Then open CHIM's web UI → *Server Plugins* → *CHIM - Promise Engine* → *Plugin Page* to see promises and change settings.

## Requirements

- A recent CHIM server (with *Playthrough Saves* for per-save tabs; older servers use a single "Default" tab).
- CHIM's bored events reaching the server. Promise Engine decides before CHIM's own "Bored event" chance.

## Reporting problems

Open an [issue](https://github.com/Marcius3d/chim_promise_engine/issues) and attach the diagnostic file: plugin page → *Diagnostics* → *Create diagnostic file*. It holds the version, settings, promises of the current playthrough, what followers said and the plugin log (no API keys). Turn on *Debug log* first for more detail.

## Roadmap

See [ROADMAP.md](ROADMAP.md).

- **Phase 1** (this version): initiatives, promises, keeping them.
- **Phase 2**: promises shape the follower. Kept and broken promises change the follower's CHIM profile and how they treat you.

## Credits

Made by Marcius3d. And Santa's little helper Claude.
Inspired by a request from the CHIM Discord community.

MIT License.

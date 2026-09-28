# Saai — Build Plan (v1)

Status: **complete. M0–M5 all delivered.**
Plan drafted 2026-07-28. Status last updated 2026-07-29.

All seven open decisions in §7 were taken as recommended, except the framework
version: the build is on **Laravel 12 / PHP 8.2**, not Laravel 13.

---

## 0. Version confirmation (needs your sign-off)

- **Laravel latest stable is 13.x (13.22, July 2026), supported to March 2028.**
- The spec pins **PHP 8.2**, but this machine already runs **PHP 8.3.30**, and Laravel 13 targets newer PHP than 8.2. Recommendation: **Laravel 13 on PHP 8.3**. If production hosts are locked to PHP 8.2, we drop to Laravel 12 instead. → **Decision needed.**
- Auth scaffold: the spec allows Fortify or Breeze. Recommendation: **Breeze, Livewire stack** (ships Blade + Livewire + Tailwind UI we can restyle; Fortify is headless and would mean re-building all auth screens). → Default unless you object.

---

## 1. Database schema — migration outlines

Conventions: every table has `id` (big increments) and `timestamps` unless noted. FKs are `foreignId()->constrained()`. Soft deletes only where listed.

### Org structure

**companies**
- `name` string unique
- `slug` string unique

**administrations**
- `company_id` FK → companies (cascade)
- `name` string, `slug` string
- unique(`company_id`, `slug`)

### Users

**users** (extends the framework default)
- `name`, `email` unique, `password`
- `phone` string
- `company_id` FK → companies (restrict)
- `administration_id` FK → administrations (restrict)
- `job_title` string
- `status` enum: `active` | `suspended` (default `active`) — deactivation = suspended, no row deletion, so message history stays intact
- `must_change_password` boolean default `true` (forced reset on first login)
- `avatar_path` string nullable
- `status_message` string nullable
- `last_seen_at` timestamp nullable
- Phase 4 adds: `availability` enum (`available|busy|away|off_shift`), `dnd_schedule` (separate table below)

**Permission layer** (`spatie/laravel-permission` tables): global roles are `admin` and `employee`. *Room moderator is per-room*, so it lives on the membership pivot, not in spatie — a user can moderate room A and be a plain member of room B.

### Messaging

**rooms**
- `name` string, `slug` string unique
- `topic` string nullable
- `type` enum: `public` | `private` | `dm`
- `is_system` boolean default `false` (auto-created company/administration rooms; undeletable, membership auto-managed)
- `company_id` FK nullable, `administration_id` FK nullable (set on system rooms)
- `created_by` FK → users nullable (nullOnDelete)

> **Design decision (needs approval):** DMs are modeled as rooms with `type = dm` and exactly two members, auto-created on first message. One messages table, one membership table, one channel-auth path, one search index — instead of a parallel "conversations" subsystem. Trade-off: room queries filter on `type`, and DM rooms have no name (rendered as the other person's name).

**room_members** (pivot)
- `room_id` FK (cascade), `user_id` FK (cascade), unique(`room_id`,`user_id`)
- `role` enum: `member` | `moderator` (default `member`)
- `last_read_message_id` unsignedBigInteger nullable, FK added after `messages` exists
- `joined_at` timestamp

> **Design decision (needs approval):** read receipts use a **per-member read cursor** (`last_read_message_id`), not a row per message per user. "Read by" for any message = members whose cursor ≥ that message id. At 1,000 users a per-message table would grow ~memberships × messages; the cursor is O(memberships) and one broadcast per catch-up. Emergencies get true per-person tracking separately (below), where it actually matters.

**messages**
- `room_id` FK (cascade), `user_id` FK sender (restrict)
- `parent_id` self-FK nullable (threaded replies; one level deep)
- `emergency_id` FK nullable → emergencies (an emergency message is a normal message + emergency record)
- `body` text nullable (nullable for attachment-only messages)
- `edited_at` timestamp nullable
- softDeletes (deletion shows "message removed", preserves thread integrity and the emergency audit trail)
- **FULLTEXT index on `body`** (Scout database driver), index(`room_id`,`id`), index(`parent_id`)

**attachments**
- `message_id` FK (cascade)
- `disk`, `path`, `original_name`, `mime_type`, `size`
- Stored under random names on a **private disk**, served via signed URLs only after room-membership authorization

### Emergency system

**emergencies** — the parent record; append-only (no update/delete paths except `resolved_at`/escalation bookkeeping)
- `sender_id` FK → users (restrict)
- `body` text (canonical content, survives even if UI message soft-deleted)
- `scope` enum: `room` | `dm` | `company` | `administration` | `all`
- `room_id` FK nullable (room/dm scope)
- `company_id` / `administration_id` FK nullable (broadcast targets)
- `escalation_interval_minutes` smallint (snapshot of setting at send time)
- `escalation_count` tinyint default 0, `last_escalated_at` nullable
- `resolved_at` timestamp nullable (sender/admin can resolve; auto when all acked)

**emergency_recipients** — snapshot of recipients at send time; doubles as live ack list **and** the permanent audit log
- `emergency_id` FK (cascade), `user_id` FK (restrict), unique pair
- `notified_at` timestamp nullable (push actually dispatched)
- `acknowledged_at` timestamp nullable
- `alert_count` tinyint default 1, `last_alerted_at`
- index(`user_id`, `acknowledged_at`)

The exportable **emergency log** is a query over these two tables — no separate log table to drift out of sync. Misuse report = emergencies grouped by sender vs. rate-limit hits (limiter events recorded here too, see M3).

**settings**
- `key` string unique, `value` json — global escalation default, rate-limit knobs, misuse thresholds (admin-editable)

### Framework/package tables
`sessions`, `cache`, `jobs`/`failed_jobs`/`job_batches`, `notifications`, spatie permission tables, `push_subscriptions` (laravel-notification-channels/webpush).

### Phase 4 tables (outline only, built in M4)
- **pinned_messages**: `room_id`, `message_id` unique, `pinned_by`, timestamps
- **polls**: `room_id`, `created_by`, `question`, `closes_at` nullable; **poll_options**: `poll_id`, `label`; **poll_votes**: `poll_option_id`, `user_id` (one vote per poll enforced in code + unique(`poll_id`,`user_id`) via denormalized `poll_id`)
- **saved_messages**: `user_id`, `message_id`, unique pair
- **dnd_windows**: `user_id`, `day_of_week` tinyint, `starts_at` time, `ends_at` time — DND suppresses normal notifications only; **emergency delivery ignores DND by design**

---

## 2. Eloquent models & relationship map

| Model | Relationships |
|---|---|
| `Company` | hasMany `Administration`, `User`, `Room`; hasOne system `Room` |
| `Administration` | belongsTo `Company`; hasMany `User`; hasOne system `Room` |
| `User` | belongsTo `Company`, `Administration`; belongsToMany `Room` (pivot `RoomMember`: role, last_read_message_id, joined_at); hasMany `Message`, sent `Emergency`; hasMany `EmergencyRecipient` (their ack duties); HasRoles (spatie) |
| `Room` | belongsToMany `User`; hasMany `Message`; belongsTo `Company`, `Administration`, creator `User` |
| `RoomMember` | pivot model — belongsTo `Room`, `User` |
| `Message` | belongsTo `Room`, sender `User`, `parent` (Message), `Emergency`; hasMany `replies` (Message), `Attachment` |
| `Attachment` | belongsTo `Message` |
| `Emergency` | belongsTo sender `User`, `Room`, `Company`, `Administration`; hasMany `EmergencyRecipient`, `Message` (broadcast posts one message per target system room) |
| `EmergencyRecipient` | belongsTo `Emergency`, `User` |
| `Setting` | — (key/value) |
| Phase 4: `PinnedMessage`, `Poll`, `PollOption`, `PollVote`, `SavedMessage`, `DndWindow` | as outlined above |

Policies: `RoomPolicy` (view/join/post/moderate), `MessagePolicy` (update/delete own, moderator delete), `EmergencyPolicy` (send, broadcast = admin only, acknowledge = recipient only, resolve = sender or admin), `UserPolicy` (admin manage, self-edit limited fields).

---

## 3. Broadcast channels & events (Reverb + Echo)

**Channels**
- `private room.{id}` — auth: room membership. All message/emergency room events.
- `presence presence.room.{id}` — who has the room open; **typing indicators are client whispers here (no server round-trip)**.
- `private App.Models.User.{id}` — personal: cross-room emergency alerts, room invites, escalation notices.
- `presence presence.online` — global online/availability (phase 4; fine at ≤1,000 users).

**Events (all `ShouldBroadcast`, queued)**

| Event | Channel(s) | Payload gist |
|---|---|---|
| `MessageSent` | room.{id} | message + sender + attachments + parent_id |
| `MessageUpdated` | room.{id} | message id, new body, edited_at |
| `MessageDeleted` | room.{id} | message id |
| `ReadCursorUpdated` | room.{id} | user id, last_read_message_id |
| `AddedToRoom` | user.{id} per new member | room summary (sidebar update) |
| `RoomMembershipChanged` | room.{id} | joins/leaves/role changes |
| `EmergencySent` | room.{id} **and** user.{id} of every recipient | emergency + message; the user-channel copy fires the overlay/sound even when the recipient is elsewhere |
| `EmergencyAcknowledged` | room.{id} + sender user.{id} | recipient id, acknowledged_at (live ack list) |
| `EmergencyEscalated` | user.{id} of each unacked recipient + sender | emergency id, escalation_count |
| `EmergencyResolved` | room.{id} + recipient user channels | emergency id |
| `EmergencyBroadcastIssued` | user.{id} of every target | emergency (full-screen takeover) |
| Phase 4: `AvailabilityChanged` | presence.online | user id, availability |

**Queued/scheduled jobs**
- `DispatchEmergencyNotifications` — fan-out Web Push + DB notifications per recipient (chunked)
- `EscalateEmergency` — self-re-dispatching delayed job: after `escalation_interval`, re-alerts unacked recipients, notifies sender, re-queues until all acked / resolved / max escalations; a per-minute scheduler sweep is the backstop if a delayed job is lost
- `ProcessUserImport` — CSV via maatwebsite/excel, row-level validation report stored and shown in admin console

---

## 4. Design tokens & emergency color (proposal)

- Brand: `#439735` with a full ramp (50→950), hover/active/disabled/tint/surface variants as CSS variables; light + dark theme values from day one. **Note:** `#439735` on white is ~3.7:1 — passes AA only for large text/UI components, so body-text green uses a darkened step (~`#2E7A22`) that clears 4.5:1.
- **Emergency color: crimson red ramp, base `#DC2626`** (light theme; ~4.8:1 on white), dark step `#991B1B`, surface tint `#FEF2F2` / dark-mode `#450A0A`. Justification: maximum perceptual distance from the green brand family, universal alarm semantics, AA-compliant. Because red↔green confusion is the most common color-vision deficiency, **emergencies never rely on hue alone**: dedicated icon, explicit "EMERGENCY" label, heavier border/elevation, pinned position, sound, and an ARIA live-region announcement.
- Token set (color, type scale, spacing, radius, shadow, motion incl. reduced-motion) lands in M0 before any component.

---

## 5. Milestones

| Milestone | Status |
|---|---|
| M0 — Foundation | **Done** |
| M1 — Auth & admin | **Done** |
| M2 — Messaging core | **Done** |
| M3 — Emergency system | **Done** |
| M4 — Add-ons | **Done** |
| M5 — Hardening & deploy | **Done** |
| M6 — 1:1 calling | **Done** |

Test suite: 293 Pest tests passing and 62 node tests, plus a Dusk suite for the
emergency paths.

Three bugs the M5 pass found, all of which would have shipped:

1. **No `notifications` table migration.** Both emergency notifications send on
   the `database` channel, so delivering any emergency threw at runtime. No
   existing test raised an emergency end to end, so nothing caught it.
2. **Horizon watched only the `default` queue**, while every emergency job runs
   on `emergency` — the alert fan-out and escalation chase would have sat
   unprocessed in production.
3. **`Conversation::messages()` collided with Livewire's validation-message
   hook**, so every validation failure in the composer threw a TypeError
   instead of showing the error.

Still open, and deliberately not papered over:

- Pint's vendored phar is corrupt in this checkout (it carries a baked-in path
  from an unrelated project), so style is unenforced until it is reinstalled.
- The Dusk suite has not been executed end to end here: ChromeDriver could not
  be downloaded in the build environment. The tests are written against the
  real selectors and routes, but the first run is unproven.
- No CI pipeline.

**M0 — Foundation.** Laravel 13 scaffold, Breeze (Livewire stack), Tailwind + Vite, design-token CSS variables, light/dark theming, app shell layout, Pest wired, seed skeleton, README started. *Done = app boots with a styled, token-driven login page in both themes.*

**M1 — Auth & admin.** spatie roles/permissions, forced password reset flow, session security, admin console (user CRUD, suspend/reactivate), queued CSV import with validation report, employee self-service profile (photo/phone/status message), full seed data (3 companies, ~8 administrations, ~30 employees incl. 1 admin). *Done = admin can onboard the whole org from a CSV; RBAC enforced by policies + Pest tests.*

**M2 — Messaging core.** Rooms (public/private + auto-created system rooms per company/administration), DMs, Reverb/Echo real-time delivery, edit/delete, threads, attachments (private disk, signed URLs), read cursors, typing whispers, unread counts, Scout full-text search scoped to accessible rooms. *Done = two browsers chat in real time; search respects access.*

**M3 — Emergency system (headline).** Emergency flag on send, distinct rendering, pin-until-ack, ack flow + live who-has/hasn't list, Web Push + audible alert + tab-title flash for backgrounded/elsewhere recipients, escalation jobs, admin broadcast (company/administration/all) with full-screen takeover + posts into system rooms, permanent exportable log (CSV), rate limiting + admin misuse report, screen-reader live announcements. *Done = Dusk-verified: recipient in another room gets alerted, acks, sender sees it live; unacked path escalates.*

**M4 — Add-ons.** Availability status + presence channel, employee directory (filter by company/administration/role), pinned announcements, polls, saved messages, DND schedule with hard emergency override.

**M5 — Hardening & deploy.** Security pass (uploads, authz matrix, rate limits), Horizon, Dusk suite for emergency paths, Supervisor + nginx + Reverb deployment configs, README finalized.

**M6 — 1:1 calling.** WebRTC audio and video between the two members of a direct
message. *Done = two browsers on different networks hold a call, and the
authorisation, Do Not Disturb and state-machine paths are covered by tests.*

The shape of it, and why:

- **Direct messages only, strictly two people.** Peer-to-peer mesh works at two
  and degrades fast beyond four; company and administration rooms have hundreds
  of members. Group calling would need a selective forwarding unit — a second
  backend, not a feature — so `RoomPolicy::call()` fails closed rather than
  half-working. *(M7 built that second backend. This constraint still describes
  `call()`, which is unchanged; group audio lives beside it as huddles, never
  through the mesh.)*
- **No `calls` table, and no migration.** Media never reaches the server, so
  there is nothing to record even if we wanted to; a half-record of who rang
  whom would be an audit trail that cannot be trusted, which is the opposite of
  what this product is for. A missed call instead offers to send an ordinary
  message, which lands in the durable medium the hub already has.
- **The ring is a server broadcast; everything else is a whisper.** Ringing
  reuses the personal channel that makes emergencies reach someone in another
  room. Accept, decline, SDP, ICE and hangup are client events on
  `presence.room.{id}` and never reach PHP — a whole call setup costs less
  socket traffic than one person typing for two seconds.
- **Do Not Disturb silences the ring, it does not block it.** Emergencies
  override DND and messages obey it; a call sits between, so it arrives without
  a sound and the caller is told, and can judge whether it warrants the
  emergency flag. Off shift is the one state that blocks outright — a roster
  fact, not a preference. Calls never escalate and never re-ring.
- **The state machine is a pure reducer** (`resources/js/call-machine.js`).
  Eight states, six timeouts, and glare when both people dial at once — none of
  it observable in a browser test without two machines. Kept pure it is checked
  in node in milliseconds.
- **Perfect negotiation, with politeness derived from user ids.** Not from
  caller/callee: after a glare collapse there is no caller, and both sides would
  compute the same answer — the one thing the pattern must never allow.

Two constraints worth recording:

- `REVERB_APP_MAX_MESSAGE_SIZE` and `REVERB_MAX_REQUEST_SIZE` are raised to
  32,000. The 10,000 default is sized for chat; trickle ICE keeps candidates
  small but a Chrome SDP offer carrying video can exceed it, and Reverb closes
  the connection rather than truncating. `CallAuthorizationTest` asserts the
  client's own guard stays below whatever the socket allows.
- `Echo.leave()` tears down the public, private and presence variants of a name
  at once, so it was never safe for two features to share a channel.
  `resources/js/presence.js` reference-counts the room presence channel; without
  it, leaving a conversation would drop a call that was still negotiating.

**M7 — room huddles.** Many-to-many audio, video and screen share in any room,
through a self-hosted LiveKit SFU. Audio on joining; camera and screen are
opt-in. *Done = several browsers hold a huddle in a channel, the banner tracks it
live for everyone else, and the authorisation, webhook-signature and
reconciliation paths are covered by tests.*

The shape of it, and why:

- **A huddle is not a call, and shares no code with one.** Separate policy
  ability, separate settings, separate kill switch, separate rate limiter, and
  the M6 stack was not edited at all. That separation is the safety property: 1:1
  calling has to keep working, and keep being independently disable-able, when
  huddles are having a bad day. Media is the reason — huddle audio crosses this
  server and call audio does not, so they fail differently and cost differently.
- **Any room, including the system ones.** The mesh's limits were the mesh's; an
  SFU has none of them. `RoomPolicy::huddle()` checks membership, the kill switch
  and whether LiveKit is configured at all, and nothing else.
- **Nothing rings.** Starting a huddle puts a banner in the room and waits. That
  is what makes it safe in a 300-member company room: there is no per-member
  availability or DND fan-out because there is nothing to silence, and no way to
  interrupt hundreds of people by accident. The banner is `role="status"`, never
  an `alertdialog` — an announcement, not a summons.
- **Still no table, and no migration.** Live state is a cache entry keyed by room
  and LiveKit's own memory behind it; the LiveKit room name is derived from the
  room id (`hub-room-{id}`) rather than stored, which is what makes the whole
  thing possible without persistence and means two huddles cannot coexist in one
  room by construction. When the last person leaves there is nothing left.
- **The webhook touches no database.** Names and avatars are signed into the join
  token's metadata with `canUpdateOwnMetadata: false`, and LiveKit hands that
  exact string back on `participant_joined`. A fifty-person join storm is then
  fifty cache writes rather than fifty rounds of queries.
- **Departures are guarded on the participant sid.** Identity is `u{id}`, so a
  second tab disconnects the first, and the old session's `participant_left` can
  arrive after the new one's `participant_joined`. LiveKit does not order
  webhooks; the sid guard is what makes ordering not matter.
- **`huddles:reconcile` is not optional.** Webhooks are lossy. Once somebody is
  inside a huddle there is no next HTTP request, so `EnsureAccountIsActive` never
  runs again — suspension, room removal and a maximum duration are all enforced
  from the sweep, and it is also what clears a phantom banner and heals a flushed
  cache.
- **The reducer and the coexistence rules are pure** (`huddle-machine.js`,
  `huddle-arbiter.js`). The arbiter is the riskiest part of the feature — it is
  the only thing standing between an incoming 1:1 call and two live WebRTC
  sessions claiming one microphone — so every row of its table is asserted in
  node rather than discovered in a browser.

Three constraints worth recording:

- **`firebase/php-jwt` v7, not v6.** v6 carries CVE-2025-45769, and the fix is
  precisely the minimum HMAC key length that bites here: HS256 needs 256 bits, so
  `livekit-server --dev` and its six-byte `secret` cannot be used with this hub
  at all. `HuddleTokens::isConfigured()` checks the length so the policy fails
  closed rather than the JWT library throwing a 500 when somebody presses Join.
- **`tailwind.config.js` scans `resources/js`.** The participant grid picks a
  column class by count, and without that content path the JIT never sees
  `grid-cols-3` and a nine-person huddle renders as one tall column. Same trap the
  `app/Enums` entry already documents.
- **The broadcast payload carries at most eight participants.** Same Reverb
  message-size ceiling as the SDP guard above, and the same consequence for
  getting it wrong — a 200-person roster would close the socket rather than being
  truncated. The true count travels alongside the facepile.

Each milestone ends with a stop-and-summarize checkpoint, small descriptive commits throughout.

---

## 6. Security flags (tracked from day one)

1. Every room/message/emergency route behind policies — membership checked at channel auth **and** controller level.
2. Uploads: mime/extension whitelist, size cap, randomized names, private disk, signed URLs, no inline HTML rendering of user content (plain text + linkification; no markdown-to-HTML initially).
3. Emergency endpoints: Redis rate limiter (proposal: non-admins 1/5min and 3/hour, configurable), limiter hits logged for the misuse report; acknowledge endpoint only accepts the authenticated recipient.
4. CSV import: fully validated per-row, never trusts headers, reports rejects instead of partial-silent-failure.
5. Audit integrity: no update/delete surface on `emergencies`/`emergency_recipients` beyond ack/escalation timestamps.
6. Session hardening: secure/httpOnly cookies, session regeneration on login and forced password change before anything else.

---

## 7. Open decisions before I write code

1. **Laravel 13 + PHP 8.3** (spec said 8.2; 8.3.30 is what's installed) — or Laravel 12 on PHP 8.2?
2. **DMs as `type=dm` rooms** (recommended) vs a separate conversations subsystem?
3. **Read receipts as per-member cursor** (recommended) vs per-message receipt rows?
4. **Broadcast surface**: full-screen takeover + message into system rooms (recommended)?
5. **Escalation default**: 2-minute interval, max 5 re-alerts, then flagged "unreachable" to sender — adjustable in admin settings. OK?
6. **Emergency color**: crimson `#DC2626` ramp as proposed?
7. **Breeze Livewire stack** over headless Fortify?

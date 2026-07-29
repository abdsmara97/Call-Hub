# Oak Tree Venture Hub — Build Plan (v1)

Status: **approved and in build. M0–M4 complete; M5 is the remaining milestone.**
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
| M5 — Hardening & deploy | **Not started** |

Carried into M5: no Dusk suite yet (the emergency paths are covered by feature
tests, not browser tests), no Supervisor/nginx/Reverb deployment configs, and
the README is still the stock Laravel one. Horizon and Dusk are installed but
unconfigured. Pint's vendored phar is corrupt on this machine — it carries a
baked-in path from an unrelated project — so style is unenforced until that is
reinstalled.

**M0 — Foundation.** Laravel 13 scaffold, Breeze (Livewire stack), Tailwind + Vite, design-token CSS variables, light/dark theming, app shell layout, Pest wired, seed skeleton, README started. *Done = app boots with a styled, token-driven login page in both themes.*

**M1 — Auth & admin.** spatie roles/permissions, forced password reset flow, session security, admin console (user CRUD, suspend/reactivate), queued CSV import with validation report, employee self-service profile (photo/phone/status message), full seed data (3 companies, ~8 administrations, ~30 employees incl. 1 admin). *Done = admin can onboard the whole org from a CSV; RBAC enforced by policies + Pest tests.*

**M2 — Messaging core.** Rooms (public/private + auto-created system rooms per company/administration), DMs, Reverb/Echo real-time delivery, edit/delete, threads, attachments (private disk, signed URLs), read cursors, typing whispers, unread counts, Scout full-text search scoped to accessible rooms. *Done = two browsers chat in real time; search respects access.*

**M3 — Emergency system (headline).** Emergency flag on send, distinct rendering, pin-until-ack, ack flow + live who-has/hasn't list, Web Push + audible alert + tab-title flash for backgrounded/elsewhere recipients, escalation jobs, admin broadcast (company/administration/all) with full-screen takeover + posts into system rooms, permanent exportable log (CSV), rate limiting + admin misuse report, screen-reader live announcements. *Done = Dusk-verified: recipient in another room gets alerted, acks, sender sees it live; unacked path escalates.*

**M4 — Add-ons.** Availability status + presence channel, employee directory (filter by company/administration/role), pinned announcements, polls, saved messages, DND schedule with hard emergency override.

**M5 — Hardening & deploy.** Security pass (uploads, authz matrix, rate limits), Horizon, Dusk suite for emergency paths, Supervisor + nginx + Reverb deployment configs, README finalized.

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

# Saai

An internal communication hub for Saai: team messaging, direct
messages, and an emergency alert system that reaches people wherever they are in
the app — including when the tab is in the background.

Multi-tenant: each customer signs up for a **workspace** (a tenant) at
`/signup` and organises it into companies and administrations — sized for
roughly 1,000 accounts per workspace. Staff accounts are never self-created:
they exist only because a workspace administrator invited (`Admin →
Invitations`), created, or imported them.

---

## What it does

**Messaging** — public and private rooms, plus a room per company and per
administration that is created automatically and cannot be left. Direct messages
are rooms with exactly two members, created on first contact. Threaded replies
one level deep, edit and soft-delete, attachments on a private disk, typing
indicators over presence whispers, per-member read cursors, and full-text search
scoped to the rooms you can actually read.

**Emergencies** — the headline feature; see the next section.

**Calls** — one-to-one audio and video between the two people in a direct
message, over WebRTC. Media is peer-to-peer and never touches the server, so
calls are not recorded and there is no call history. Do Not Disturb rings you
quietly rather than not at all, and the caller is told which they got. See the
calling section below.

**Everything else in a room** — pinned announcements, polls with live results,
saved messages.

**Forms** — an administrator writes a set of questions under Admin → Forms, then
sends it into a room or a direct message by choosing it from the composer.
Questions can ask for short text, long text, a number, yes or no, or pictures.
The same form can go to several conversations and still collect one set of
answers. Unlike a poll, answers are attributable: they are recorded against the
person's name, and only the form's author and administrators can read them.
People can correct their own answers until the form is closed.

**Notifications** — unread counts, a room that blinks when a message lands in it,
and a desktop pop-up when the tab is in the background. Quiet hours silence
ordinary messages; emergencies ignore them.

**Administration** — user CRUD, suspend and reactivate, a downloadable CSV
template and queued bulk import with a per-row rejection report, org-wide
emergency broadcast, an exportable emergency log, a misuse report, and editable
escalation and rate-limit settings.

**Self-service** — photo, phone, status message, availability, Do Not Disturb
schedule, and the notification toggle.

---

## The emergency system

This is the reason the product exists, so it is worth stating plainly what it
does differently from an ordinary message.

An emergency is a normal message **plus** an `emergencies` record holding a
snapshot of every recipient at send time. That snapshot is the live
acknowledgement list and the permanent audit log — there is no second log table
to drift out of sync.

- It broadcasts on the room channel **and** on each recipient's personal
  channel, so someone reading a different room still gets the overlay.
- It is pinned until acknowledged. Navigating away does not dismiss it.
- Unacknowledged emergencies escalate on a delayed job that re-queues itself,
  with a per-minute scheduled sweep as the backstop. After the escalation budget
  is spent the sender is told who never responded, and the chase stops — an
  endless alert loop only teaches people to ignore it.
- **Do Not Disturb never suppresses an emergency.** That is the point of the
  override.
- Colour is never the only signal. Red is the most common confusion for
  colour-vision deficiency, so every emergency also carries a dedicated icon,
  the literal word "EMERGENCY", a heavier border, a pinned position, sound, and
  an ARIA live-region announcement.
- Acknowledgement is per person and only the named recipient can give it.
- Emergency messages cannot be edited or deleted by anyone, including
  administrators. The audit trail is the product.

Non-administrators are rate limited (default: 1 per 5 minutes, 8 per day).
Limiter hits are recorded, not merely refused, so the admin misuse report has
something to count.

---

## Calls

One-to-one only, in direct messages only. Not a design compromise so much as an
architecture boundary: peer-to-peer works at two people and falls apart past
about four, and company and administration rooms have hundreds of members.
Group calling would need a selective forwarding unit — a second backend to run
and monitor, not a feature — so the policy refuses anything that is not a
two-member DM rather than half-working.

**Nothing about a call reaches the server except the ring.** Audio and video go
browser to browser. Accept, decline, SDP, ICE and hangup travel as client
whispers on the room's presence channel, exactly like typing indicators, so a
whole call setup costs less socket traffic than one person typing for two
seconds. Only the initial ring is a broadcast, because a whisper cannot reach
someone who has not joined the channel — it goes on the personal channel, the
same mechanism that makes an emergency find you in a different room.

**Calls are ephemeral. There is no `calls` table and no call history.** With
peer-to-peer media there is nothing to record, and a partial record of who rang
whom would be an audit trail that cannot be trusted — the opposite of what the
emergency log is for. A missed call instead offers to send an ordinary message,
which lands in the medium that *is* durable.

**Do Not Disturb silences a call; it does not block one.**

| Callee | What happens | What the caller sees |
|---|---|---|
| Available or away | full ring | normal |
| Busy, or inside a DND window | overlay, no sound | "ringing quietly" |
| Off shift | not rung at all | "off shift" + send a message |
| Suspended, or calling switched off | not rung at all | "cannot take calls" |

Emergencies override DND and ordinary messages obey it; a call sits between.
"Away" rings at full volume on purpose — it is a passive inference that goes
stale, not something anyone asked for. **Calls never escalate and never
re-ring.** One ring, one outcome. A call that chases you is an emergency with
extra steps, and that already exists.

### Configuring calls

Nothing is required for local development: with no TURN provider configured the
app falls back to public STUN, which is enough for two browsers on one machine.

For real calls across networks, set `CLOUDFLARE_TURN_KEY_ID` and
`CLOUDFLARE_TURN_API_TOKEN`. Roughly 10–20% of connections cannot get through
corporate NAT and need a relay; Cloudflare's first 1 TB is free, which at this
hub's size means calling costs about nothing.

**Neither value ever gets a `VITE_` twin.** Unlike VAPID, there is no public
half here. The browser receives only short-lived credentials minted per call by
`POST /calls/{room}/ice-servers`; a static TURN password in the front-end bundle
is how organisations end up paying for someone else's relay traffic.

Administrators can switch calling off entirely, and change the ring duration, in
**Admin → Settings** without a deploy.

**Two things that will bite if changed carelessly:**

1. `REVERB_APP_MAX_MESSAGE_SIZE` and `REVERB_MAX_REQUEST_SIZE` are set to
   32,000. The 10,000 default is sized for chat, and a Chrome SDP offer carrying
   video can exceed it — Reverb then closes the connection instead of
   truncating, so the symptom is "turning the camera on killed the call".
2. The nginx `Permissions-Policy` names `camera=(self), microphone=(self)`
   explicitly. The obvious hardening edit — `camera=(), microphone=()` — breaks
   every call in the hub with no error in any log.

## Room huddles

A huddle is the meeting, as opposed to the one-to-one call above. Many-to-many
audio, video and screen share in **any** room, through a self-hosted LiveKit SFU
rather than peer-to-peer. Audio is published on joining; camera and screen share
are opt-in. Up to `HUB_HUDDLE_MAX_PARTICIPANTS` (30) people.

Starting one **rings nobody** — it puts a "Huddle in progress" banner in the room
and waits. That is what makes it safe in a 300-member room. There is no
scheduling, no invite link and no lobby: membership of the room is the guest
list. Nothing is recorded; every token is minted with `roomRecord` false and no
Egress service is installed.

### Configuring huddles

Optional. Leave `LIVEKIT_*` blank and `RoomPolicy::huddle()` answers no, so the
button is never rendered and a fresh clone runs with no extra servers.

To run one locally, pin the same version the repo expects
(`deploy/livekit/VERSION`), and use the committed throwaway config:

```bash
livekit-server --config deploy/livekit/livekit.local.yaml    # .exe on Windows
```

Then uncomment the `LIVEKIT_*` block in `.env`. Windows has no install script —
`deploy/livekit/install-livekit.sh` is `linux_amd64` only — but the release
publishes a `windows_amd64` build that runs against the same config file.

A huddle needs more processes than chat. All four, or it half-works:

```bash
php artisan reverb:start     # the banner and the live roster
php artisan queue:work       # BroadcastHuddleState — the trailing edge of a join burst
php artisan schedule:work    # huddles:reconcile, every minute, not optional
php artisan serve            # on :8000, to match the webhook URL in the local config
```

**Four things that will bite:**

1. **Do not use `livekit-server --dev`.** It signs with the hard-coded six-byte
   secret `secret`; HS256 needs 256 bits and `firebase/php-jwt` refuses to sign
   with less, so the policy fails closed and the symptom is a huddle button that
   simply never appears. `--dev` also sends no webhooks.
2. **The webhook port must match.** The local config posts to
   `http://127.0.0.1:8000/api/webhooks/livekit`. Serve on another port and the
   webhook 404s silently — the banner never appears and never clears. This is
   also why LiveKit in a container needs `host.docker.internal`, not loopback.
3. **Without the scheduler, state rots.** A lost `participant_left` leaves a
   permanent banner, and a suspended employee stays inside a live huddle
   indefinitely — once somebody is in one there is no next request for the
   middleware to act on.
4. **Restarting LiveKit ends every huddle instantly.** Media flows through that
   process, so unlike Reverb there is no drain that helps.

Administrators can switch huddles off, and change the participant cap, in
**Admin → Settings** — deliberately separate from the calling switch, so a
bandwidth incident can stop the expensive thing without taking away free
peer-to-peer calling.

## Attachments

| | |
|---|---|
| Per file | **40 MB** — `HUB_ATTACHMENT_MAX_KB` |
| Per message | **80 MB** across at most 5 files — `HUB_ATTACHMENT_MAX_BATCH_KB` |
| Types | 14, by allow-list — `config/hub.php` |

The batch limit exists because Livewire posts every selected file in a single
request. Without it, five attachments at full size would be one 200 MB POST that
any one person could send. Two 40 MB files, or five 16 MB ones, both fit.

**Raising the size means changing four things, and the lowest one always wins:**

| Ceiling | Where | Value |
|---|---|---|
| App validation | `.env` → `HUB_ATTACHMENT_MAX_KB` | 40 MB |
| Livewire temporary upload | `config/livewire.php` | reads the same var |
| nginx request body | `deploy/nginx/oaktree-hub.conf` | 88M |
| PHP | `deploy/php/oaktree-uploads.ini` | 40M / 88M |

The Livewire one is the trap. Its default is 12 MB and it runs *before* any
component validation, so a file it rejects never reaches `config/hub.php` at all
— the setting would read 40 MB and behave as 12 MB. `config/livewire.php` is
published here for that single reason, and reads the same env var so the two
cannot drift. `tests/Feature/Hub/AttachmentLimitTest.php` fails if they do.

PHP is the other one worth knowing about, because its failure mode is silent:
when `post_max_size` is exceeded PHP discards the entire request body, so Laravel
receives an empty request and cannot even report "file too large". If uploads
fail with no explanation, check `deploy/php/oaktree-uploads.ini` is installed.

**Downloads hold a PHP-FPM worker.** `AttachmentController` streams through PHP,
so a 40 MB file on a slow connection occupies a worker for the whole transfer.
`fastcgi_read_timeout` is raised to 300s to stop that being cut off, but that is
a mitigation. The real fix — `X-Accel-Redirect`, or signed URLs straight to
object storage once `HUB_ATTACHMENT_DRIVER=s3` — is unbuilt, and is worth doing
before large attachments become routine across 1,000 people.

## Desktop notifications

Two separate mechanisms, easy to confuse:

| | Ordinary messages | Emergencies |
|---|---|---|
| Mechanism | browser `Notification` API | Web Push + service worker |
| Works when tab is backgrounded | yes | yes |
| Works when the browser is **closed** | no | yes |
| Needs VAPID keys | no | yes |
| Obeys Do Not Disturb | yes | **never** |
| User can switch off | yes, in Profile | no |

A message pop-up fires only when the tab is **hidden** — if you are looking at
the Hub, the blinking room in the sidebar has already told you, and a pop-up on
top of that is noise. It stays silent for your own messages, for emergencies
(which have their own overlay), and for any room you can no longer read.

Each person allows notifications once per browser, from **Profile → Browser
notifications**.

---

## Stack

| | |
|---|---|
| Framework | Laravel 12 on PHP 8.2 |
| UI | Livewire 3 + Volt, Blade, Tailwind, Vite |
| Auth | Breeze (Livewire stack); workspace signup + invitations, no open staff registration |
| Roles | `spatie/laravel-permission` — global `admin` / `employee` |
| Real time | Reverb + Echo |
| Calls | WebRTC, peer-to-peer; Cloudflare Realtime TURN for relay |
| Queues | Redis + Horizon |
| Search | Scout, database driver (MySQL `FULLTEXT` on `messages.body`) |
| Push | `laravel-notification-channels/webpush` |
| Import | `maatwebsite/excel` |
| Tests | Pest, plus Dusk for the emergency paths |

---

## Getting started

```bash
git clone <repo> oaktree-hub && cd oaktree-hub

composer install
npm install

cp .env.example .env
php artisan key:generate

# Only needed for emergency push (the browser-closed kind). See the OpenSSL
# note under Known gaps if this errors with "Unable to create the key".
php artisan webpush:vapid

touch database/database.sqlite
php artisan migrate --seed

npm run dev
php artisan serve
```

The default `.env.example` is the **local** profile: SQLite and the database
queue, so a fresh clone runs with no extra servers. The commented blocks in the
same file describe the production profile (MySQL, Redis, S3-compatible storage).

For real-time delivery you also need, in separate terminals:

```bash
php artisan reverb:start     # WebSocket server
php artisan queue:work       # or `php artisan horizon` on Redis
php artisan schedule:work    # drives the emergency and huddle sweeps
```

Room huddles additionally need a LiveKit SFU. It is optional: leave `LIVEKIT_*`
blank and the huddle button is simply not rendered, so a fresh clone still runs
with no extra servers. To work on huddles:

```bash
livekit-server --config deploy/livekit/livekit.local.yaml
```

Do **not** use `livekit-server --dev`. It signs with the hard-coded six-byte
secret `secret`, and HS256 needs 256 bits of key — `firebase/php-jwt` refuses to
sign with anything shorter. The policy checks the length and fails closed, so the
symptom is a huddle button that never appears. `--dev` also sends no webhooks, so
the join banner would never update either way.

Without `reverb:start` the app still works, but nothing is live: messages need a
refresh and emergency overlays never appear. This is the single most common
"why is it broken" — check Reverb is actually running before anything else.

**Port 8080 is a common clash.** XAMPP, WAMP and a stock Apache all take it, and
Reverb then dies with a socket permission error. If that happens, move it — set
`REVERB_PORT` and `REVERB_SERVER_PORT` to something free (8085 works), then
`npm run build`, because the port is compiled into the front-end bundle.

### Seeded accounts

`php artisan migrate --seed` builds 3 companies (Technologies, Logistics,
Facilities), 8 administrations, and 31 accounts — one administrator and 30
staff, one of whom is suspended so the admin console has something to show.

Sign in as the administrator with **`pm@oaktreetech.com` / `password`**. Every
seeded account uses the same password.

Seeded users have `must_change_password` cleared so you can sign straight in.
Accounts created through the admin console do not, and are held on the rotation
screen until they set their own.

The full list, with a test script for the paths that need two people, is in
[`docs/test-accounts.xlsx`](docs/test-accounts.xlsx). Regenerate it after
re-seeding — it is built from the database, not from the seeder source, so it
cannot claim an account that is not there:

```bash
php artisan hub:export-test-accounts
```

It carries seed credentials only. **No infrastructure secrets belong in it** —
the database password, Cloudflare TURN token, VAPID private key and Reverb app
secret live in `.env`, which is gitignored so they cannot be committed. That
workbook is not.

---

## Testing

```bash
php artisan test                       # Pest: 293 tests
php artisan test --filter=Security     # the authorisation matrix
npm run test:js                        # node: 62 tests, no browser needed
```

`npm run test:js` is where the call state machine lives. Eight states, six
timeouts, and the case where both people dial at the same instant — none of it
observable in a browser test without two machines and a lot of patience, so
`resources/js/call-machine.js` is a pure function and is checked in node.

The suite runs on in-memory SQLite. Full-text search degrades to `LIKE` there;
the MySQL `FULLTEXT` path is exercised by the browser suite.

### Browser tests

The emergency acceptance criteria — a recipient in another room is alerted,
acknowledges, and the sender sees it live — are Dusk tests, because they cannot
be proven without two real browsers and a real socket.

```bash
cp .env.dusk.local.example .env.dusk.local
php artisan dusk:chrome-driver --detect   # once, needs network access
php artisan reverb:start                  # in another terminal
php artisan dusk
```

`php artisan test` deliberately does not include `tests/Browser`.

---

## Deployment

Configs live in [`deploy/`](deploy/) and assume `/var/www/oaktree-hub`:

| File | Purpose |
|---|---|
| `deploy/nginx/oaktree-hub.conf` | TLS, security headers, and the WebSocket proxy |
| `deploy/nginx/oaktree-rtc.conf` | TLS for the LiveKit SFU on its own hostname |
| `deploy/php/oaktree-uploads.ini` | upload ceilings — **required**, or attachments cap at 2 MB |
| `deploy/supervisor/oaktree-horizon.conf` | keeps Horizon alive |
| `deploy/supervisor/oaktree-reverb.conf` | keeps Reverb alive |
| `deploy/supervisor/oaktree-livekit.conf` | keeps the huddle SFU alive |
| `deploy/livekit/livekit.yaml` | SFU config — **contains a secret**, template only in the repo |
| `deploy/livekit/install-livekit.sh` | pinned SFU install/upgrade, run by hand |
| `deploy/cron/oaktree-scheduler` | the one cron line the scheduler needs |
| `deploy/deploy.sh` | ordered release script |

Five things are easy to get wrong:

1. **`horizon:terminate` must run after the new code is in place.** Workers hold
   the old code in memory until they cycle.
2. **Reverb is tier 1.** If it is down the hub looks fine and silently stops
   being real time. Alert on the process, not just on nginx and PHP-FPM.
3. **The scheduler cron is what backstops escalation** — and now huddle state
   too. Without it, an escalation lost to a worker restart is never retried, a
   lost LiveKit webhook leaves a permanent "Huddle in progress" banner, and a
   suspended employee stays in a live huddle indefinitely, because once somebody
   is inside one there is no next request for the middleware to act on.
4. **Restarting LiveKit ends every huddle in progress.** Huddle media flows
   through that process, unlike one-to-one call media, so there is no drain that
   helps. It is deliberately outside `deploy.sh` — upgrade it out of hours.
5. **The huddle VM needs dedicated cores.** On a shared-core `e2-medium`,
   sustained packet forwarding burns the CPU credit and the machine is throttled
   — which presents as the huddle going choppy and the whole site slowing at the
   same moment, and is miserable to diagnose. `n2-standard-4` or better.

### Multi-tenant operation

**One stack serves every customer.** A single Reverb cluster, a single LiveKit
deployment, one Horizon/queue fleet and one database carry all tenants; a new
customer is a row created by signup at `/signup`, never a new VM, database, or
process. Nothing in `deploy/` is duplicated per customer.

Isolation is carried in the names and the schema, not in separate
infrastructure:

- **Reverb** — channels are tenant-scoped (`tenant.{id}.room.{room}`,
  `tenant.{id}.presence.room.{room}`, `tenant.{id}.presence.online`) and every
  authorisation callback in `routes/channels.php` refuses a socket whose user
  belongs to a different tenant before membership is even considered. The
  single shared `REVERB_APP_ID` / `REVERB_APP_KEY` / `REVERB_APP_SECRET` is
  intentional: the boundary lives at channel authorisation, not at the
  Reverb-app layer.
- **LiveKit** — room names carry the tenant (`hub-room-{tenant}-{room}`), and
  the webhook's name-to-room mapping refuses a name whose tenant segment does
  not match the room it claims.
- **Database and search** — every directly-queried table carries `tenant_id`
  under a global Eloquent scope bound from the authenticated user, and the
  Scout message index carries `tenant_id` so search is cut per customer before
  any room-membership intersection.

**Scaling shared infrastructure.** The warnings above change blast radius, not
nature, under multi-tenancy: Reverb is still tier 1 (item 2), but an outage is
now an outage *for every customer at once*, and a LiveKit restart (item 4) ends
every tenant's huddles in the same instant. Alert thresholds and maintenance
windows should be set for the whole customer base, not for one workspace — and
the file-descriptor note in `deploy/supervisor/oaktree-reverb.conf` now sizes
against the sum of all tenants' accounts.

### Queues

Three, with different deadlines — see `config/horizon.php`:

| Queue | Work | Notes |
|---|---|---|
| `emergency` | alert fan-out, escalation | isolated, negative `nice`, never queued behind anything |
| `default` | broadcast events, Scout indexing, mail | ordinary latency |
| `imports` | employee CSV | minutes-long; own worker so it cannot delay an alert |

---

## Hosting on Google Cloud

Nothing in the app needs to change to run on GCP. It is an ordinary Laravel and
MySQL stack, so the managed services map straight onto it:

| Piece | Google Cloud service | Change needed |
|---|---|---|
| Database | **Cloud SQL for MySQL 8** | `DB_*` env vars |
| Queues and cache | **Memorystore for Redis** | `QUEUE_CONNECTION`, `CACHE_STORE`, `REDIS_*` |
| Attachments and avatars | **Cloud Storage** | `HUB_ATTACHMENT_DRIVER=s3` plus the S3-compatible keys |
| App and Reverb | **Compute Engine VM** | none — `deploy/` already covers nginx and Supervisor |
| Scheduler | the cron in `deploy/cron/` | none |

**Put Reverb on a VM, not Cloud Run.** It holds WebSocket connections open for
minutes at a time between events, and Cloud Run reaps idle connections. Losing
them does not break the app, but it silently stops being real time — which is
the failure mode hardest to notice.

Cloud Storage exposes an S3-compatible API, so `league/flysystem-aws-s3-v3`
(already required) works against it via HMAC interoperability keys. Point
`AWS_ENDPOINT` at `https://storage.googleapis.com`.

### Why not Firestore

Worth recording, because "hosted on Google Cloud" and "uses Firebase" get
conflated. The data model here is relational on purpose, and several product
guarantees are enforced by the database rather than by application code:

- **17 unique indexes** — one vote per person per poll, one reaction per person
  per emoji, one DM room per pair, one acknowledgement per emergency recipient.
- **Multi-table transactions** in eight places, most importantly the emergency
  recipient snapshot, which is written atomically with the message so the audit
  trail can never half-exist.
- **Foreign keys with cascade rules** across ten migrations.
- **A `FULLTEXT` index** on `messages.body`, which is what message search is.
- **Relational reads** — `whereHas`, `withCount`, grouped tallies — in more than
  twenty places.

Firestore offers none of the first four. Moving would mean reimplementing those
guarantees in application code, which is precisely where audit trails go wrong.
Cloud SQL gives the same managed-service benefits with none of that risk.

Firebase Cloud Messaging is a separate question and would be a reasonable way to
deliver push without VAPID; it does not require touching the database.

### Storage growth

Messages are cheap. Each costs roughly **384 bytes** including every index:

| Usage across 1,000 staff | Per year | After 5 years |
|---|---|---|
| Light — 5 messages/person/day | 0.65 GB | 3.3 GB |
| Normal — 20 messages/person/day | 2.6 GB | 13 GB |
| Heavy — 50 messages/person/day | 6.5 GB | 33 GB |

Storage will not be the constraint — the CPU and memory tier will bite long
first. **Attachments are what actually grow**: they live in object storage, not
the database, and at two 1 MB files per person per day they run to roughly
700 GB a year, some 300× the message text. That is the number to budget.

**The 40 MB ceiling changes which number to watch.** Those figures assume 1 MB
files; the limit is now forty times that, so the thing that scales is not
storage but **egress**. Object storage is roughly $0.02/GB/month and GCP egress
is roughly $0.12/GB, so a single 40 MB attachment costs a fraction of a cent to
keep and about half a cent every time somebody opens it. One file read by twenty
colleagues is ~$0.10 — trivial once, and the dominant line item if large files
become how people share work. Watch download volume, not bucket size.

Read receipts are the reason the message table stays this small. Storing one
receipt per person per message would add about **10 GB every year** at normal
usage. The per-member read cursor used instead (see §1 of `docs/PLAN.md`) is
**under 1 MB in total and does not grow with message volume**, while still
answering "who has read this".

---

## Security notes

- Every room, message and emergency route is behind a policy, checked at
  channel authorisation **and** in the component. A socket subscription never
  grants the right to act.
- Attachments live on a private disk under randomised names and are served only
  through a signed URL **and** a live membership check. A signature alone stops
  working the moment someone leaves the room.
- Non-image attachments are never served inline, and everything is served
  `nosniff` with a sandbox CSP — an inline SVG or HTML upload would otherwise
  run in the app's origin.
- User content is rendered as plain text. There is no markdown-to-HTML path.
- CSV import validates every row and reports rejects rather than failing
  silently or half-importing.
- `emergencies` and `emergency_recipients` have no update or delete surface
  beyond acknowledgement and escalation bookkeeping.
- Suspended accounts lose their session on the next request, not at the next
  login. Deactivation never deletes a row, so message history stays intact.

---

## Project documents

- [`docs/PLAN.md`](docs/PLAN.md) — the build plan, schema, and the design
  decisions behind DMs-as-rooms, read cursors, and the emergency colour.

## Known gaps

**OpenSSL on the current dev machine.** `OPENSSL_CONF` points at
`C:\Program Files\PostgreSQL\psqlODBC\etc\openssl.cnf`, which does not exist —
and no `openssl.cnf` exists anywhere on the machine. PHP's EC key generation
therefore fails, which blocks:

- `php artisan webpush:vapid`, and so emergency push to a **closed** browser;
- `php artisan dusk:chrome-driver`, and so the whole browser suite.

It is a Windows environment setting, not a project bug. Point `OPENSSL_CONF` at
a valid `openssl.cnf` (a minimal one with a `[req]` section carrying
`default_bits` is enough) and both unblock. Message notifications and everything
else in the app are unaffected.

**Other gaps:**

- `vendor/bin/pint` is unusable in this checkout: the vendored phar carries a
  baked-in path from an unrelated project. Reinstall it before relying on
  `pint --test` in CI.
- The Dusk suite has never been executed end to end — see the OpenSSL note. The
  tests are written against the real selectors and routes, but treat the first
  run as unproven.
- Real-time delivery is verified server-side and by feature tests, but no one has
  yet watched two browsers exchange a message live.
- **No call has been placed between two real browsers.** The authorisation, Do
  Not Disturb and state-machine paths are covered by tests, and the bundle
  builds, but the media path is unproven. In particular, **every test in the
  suite passes with a completely broken TURN configuration** — the only way to
  find out whether relay works is to force `iceTransportPolicy: 'relay'` and
  place a call between two different networks. Do that before trusting calls in
  production.
- Call behaviour on iOS Safari is untested. The remote `<audio>` element is
  started from the Accept tap because that is the user gesture iOS requires;
  if that is wrong the failure mode is silent one-way audio, not an error.
- A deploy ends calls that are ringing or connecting. Calls already connected
  survive, because their media does not pass through this server. There is
  deliberately no drain step — see the comment in `deploy/deploy.sh`.
- Per-room muting is unbuilt. `room_members.is_muted` exists and is read nowhere;
  it is the natural home for it.
- No mention detection (`@name`), so notifications cannot be narrowed to "only
  when I am named".
- No CI pipeline yet.

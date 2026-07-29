# Oak Tree Venture Hub

An internal communication hub for Oak Tree Technology: team messaging, direct
messages, and an emergency alert system that reaches people wherever they are in
the app — including when the tab is in the background.

Built for a single organisation of roughly 1,000 accounts across several
companies and administrations. There is no public sign-up; accounts exist only
because an administrator created or imported them.

---

## What it does

**Messaging** — public and private rooms, plus a room per company and per
administration that is created automatically and cannot be left. Direct messages
are rooms with exactly two members, created on first contact. Threaded replies
one level deep, edit and soft-delete, attachments on a private disk, typing
indicators over presence whispers, per-member read cursors, and full-text search
scoped to the rooms you can actually read.

**Emergencies** — the headline feature; see the next section.

**Everything else in a room** — pinned announcements, polls with live results,
saved messages.

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
| Auth | Breeze (Livewire stack), registration removed |
| Roles | `spatie/laravel-permission` — global `admin` / `employee` |
| Real time | Reverb + Echo |
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
php artisan schedule:work    # drives the emergency escalation sweep
```

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

---

## Testing

```bash
php artisan test                       # Pest: 185 tests
php artisan test --filter=Security     # the authorisation matrix
```

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
| `deploy/supervisor/oaktree-horizon.conf` | keeps Horizon alive |
| `deploy/supervisor/oaktree-reverb.conf` | keeps Reverb alive |
| `deploy/cron/oaktree-scheduler` | the one cron line the scheduler needs |
| `deploy/deploy.sh` | ordered release script |

Three things are easy to get wrong:

1. **`horizon:terminate` must run after the new code is in place.** Workers hold
   the old code in memory until they cycle.
2. **Reverb is tier 1.** If it is down the hub looks fine and silently stops
   being real time. Alert on the process, not just on nginx and PHP-FPM.
3. **The scheduler cron is what backstops escalation.** Without it, an
   escalation lost to a worker restart is never retried.

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
- Per-room muting is unbuilt. `room_members.is_muted` exists and is read nowhere;
  it is the natural home for it.
- No mention detection (`@name`), so notifications cannot be narrowed to "only
  when I am named".
- No CI pipeline yet.

# Oak Tree Venture Hub

An internal communication hub for Oak Tree Technology: team messaging, direct
messages, and an emergency alert system that reaches people wherever they are in
the app — including when the tab is in the background.

Built for a single organisation of roughly 1,000 accounts across several
companies and administrations. There is no public sign-up; accounts exist only
because an administrator created or imported them.

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

# Browser push needs a VAPID pair.
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
refresh and emergency overlays never appear.

### Seeded accounts

`php artisan migrate --seed` builds 3 companies (Technologies, Logistics,
Facilities), 8 administrations, and 32 accounts — one administrator, 30 staff,
and one suspended account so the admin console has something to show.

Sign in as the administrator with **`pm@oaktreetech.com` / `password`**. Every
seeded account uses the same password.

Seeded users have `must_change_password` cleared so you can sign straight in.
Accounts created through the admin console do not, and are held on the rotation
screen until they set their own.

---

## Testing

```bash
php artisan test                       # Pest: 122 tests
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

- `vendor/bin/pint` is unusable in this checkout: the vendored phar carries a
  baked-in path from an unrelated project. Reinstall it before relying on
  `pint --test` in CI.
- The Dusk suite has never been executed end to end here — ChromeDriver could
  not be downloaded in the build environment. The tests are written against the
  real selectors, but treat the first run as unproven.
- No CI pipeline yet.

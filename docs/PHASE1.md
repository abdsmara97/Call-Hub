# Phase 1 — Multi-tenant foundation

Executed against the SaaS-readiness audit (28 Sep 2026). This note records
what changed, where, and what to watch during rollout. The test plan lives
in the suite itself (`tests/Feature/Security/TenantIsolationTest.php` and
friends); this file is the operator-facing summary.

## Schema

| Change | Migration |
|---|---|
| `tenants` table (id, name, unique slug) | `2026_09_28_000100` |
| `tenant_id` on companies, administrations, users, rooms, emergencies, settings, forms; per-tenant uniques replace global ones (company name/slug, room slug, setting key); existing rows folded into a `default` tenant | `2026_09_28_000200` |
| `invitations` table (token is the credential; unique per tenant+email) | `2026_09_28_000300` |
| Stripe customer columns on `tenants`; `subscriptions`/`subscription_items` keyed by `tenant_id` (Cashier, retargeted) | `2026_09_28_0957xx` |

Everything below the room level (messages, attachments, polls, forms
content, reactions, mentions) is deliberately transitive — reached only
through a room or user, both of which are fenced.

## Architecture

- **Scoping.** `App\Support\TenantContext` (scoped singleton) is bound from
  the authenticated user by `SetTenantContext` middleware. The
  `BelongsToTenant` trait applies a global scope *only while a context is
  bound*: queue workers, seeders, and console commands run unscoped and set
  `tenant_id` explicitly (provisioners do this). `Model::acrossTenants()`
  is the audited escape hatch.
- **Reverb.** Channel names carry the boundary: `tenant.{t}.room.{r}`,
  `tenant.{t}.presence.room.{r}`, `tenant.{t}.presence.online` — spelled in
  one place (`App\Support\BroadcastChannels`) and enforced again in
  `routes/channels.php` authorization. One shared cluster, one app id.
- **LiveKit.** Rooms are `hub-room-{tenant}-{room}`. The webhook inverse
  (`HuddleTokens::roomIdFrom`) rejects a name that claims an existing room
  under the wrong tenant, but still maps deleted rooms so
  `huddles:reconcile` can tear the orphaned LiveKit room down.
- **Scout.** The message payload carries `tenant_id`;
  `Message::searchInTenant()` makes the fence part of the call signature.
- **Onboarding.** Workspaces are created only by the platform operator
  (`platform/tenants`, gated on `users.is_super_admin`, which is never
  mass-assignable): `WorkspaceProvisioner` creates tenant + company +
  "General" administration + admin atomically, issuing the admin a
  temporary password with forced rotation. Workspace admins invite staff
  by email (`admin/users/invitations`); accepting the token creates the
  account. CSV import unchanged, as the bulk path.
- **Billing.** Cashier with **Tenant as the customer**. Seats = the
  `default` subscription (quantity = accounts); the emergency system is the
  `emergency` add-on, gated in `EmergencyPolicy` for *raising* alerts only —
  the audit log, acknowledgement, and resolution survive a lapsed card.
  `HUB_BILLING_ENFORCED=false` (default) keeps every non-SaaS install fully
  featured.

## Rollout notes — the five known-bite categories

1. **Reverb as single point of failure.** Unchanged mechanically, but the
   blast radius is now every customer at once. The channel rename also
   means: deploy the backend and the built JS bundle together — an old
   bundle subscribes to `room.{id}` and hears silence. Deploy this during
   a quiet window; wire:navigate clients pick up the new bundle on next
   full load, so force a reload via the usual asset-version bump.
2. **LiveKit restart behaviour.** Any huddle live across this deploy is
   orphaned by the *name change* even without a LiveKit restart: the old
   `hub-room-{id}` names no longer map, so `huddles:reconcile` will close
   them within its sweep. Announce that huddles will drop at deploy time.
3. **Attachment ceilings.** Untouched by Phase 1. Still the documented
   X-Accel-Redirect follow-up.
4. **TURN credentials.** Untouched — 1:1 calls remain peer-to-peer with
   short-lived credentials, per the preserved architecture decision.
5. **Scheduler dependency.** Now also the thing that closes orphaned
   huddles after the rename (see 2) and remains the emergency-escalation
   backstop for every tenant. If the cron line is missing, every customer's
   escalations stall, not one's.

Migration itself is invisible to the existing install: all rows land in the
`default` tenant and behaviour is unchanged (billing unenforced, same
channels re-derived at page load).

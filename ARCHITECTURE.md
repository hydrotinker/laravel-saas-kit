# Architecture

This document explains **why** the Laravel SaaS Kit is built the way it is. It is deliberately
opinionated: every section states the decision, the reasoning, and the trade-off or failure mode you
accept by choosing it. For _what_ the kit is and _how_ to run it, see [README.md](README.md).

---

## 1. The shape of a request

Everything below hangs off a single request lifecycle. Understanding this flow makes the rest of the
decisions legible.

```
HTTP request
   │
   ▼
Nginx  ──FastCGI──►  php-fpm (Laravel)
                          │
                          ▼
                  auth:api  ──►  JwtGuard
                  (verifies the HS256 access token, extracts `sub` + `tid`)
                          │
                          ▼
                  tenant  ──►  ResolveTenant middleware
                  (asserts `tid` present + user is a member → 403 otherwise)
                  (binds the active Tenant into TenantContext)
                          │
                          ▼
                  Controller
                          │
                          ▼
                  Eloquent query  ──►  TenantScope global scope
                  (silently appends `where tenant_id = <context>`)
                          │
                          ▼
                  Policy check (role resolved from TenantContext)
                          │
                          ▼
                  spatie/laravel-data DTO  ──►  JSON response
```

The two pillars are **authentication** (who are you, and which tenant is this token for?) and
**tenancy** (every read and write is silently constrained to that tenant). The rest is domain code.

Key files:
[app/Auth/JwtGuard.php](backend/app/Auth/JwtGuard.php),
[app/Http/Middleware/ResolveTenant.php](backend/app/Http/Middleware/ResolveTenant.php),
[app/Support/TenantContext.php](backend/app/Support/TenantContext.php),
[app/Models/Scopes/TenantScope.php](backend/app/Models/Scopes/TenantScope.php).

---

## 2. Design decisions

### 2.1 Row-level isolation, not schema-per-tenant

**Decision.** All tenants share one PostgreSQL schema. Every tenant-owned table carries a
`tenant_id` foreign key (see [database/migrations/](backend/database/migrations/), e.g.
`projects` and `tasks`), and rows are partitioned logically, not physically.

**Why.**

- **Operational simplicity.** One schema means one migration run, one connection pool, one backup.
  Schema-per-tenant multiplies every migration by the tenant count and turns a routine deploy into a
  fan-out job that can partially fail.
- **Cheap cross-tenant work.** Platform analytics, admin tooling, and "count all projects" are
  ordinary `SELECT`s. With schema-per-tenant they become loops or `UNION`s across N schemas.
- **No connection juggling.** There is no per-request `SET search_path` or database switch, so the
  request path stays flat and poolable.

**Trade-off you accept.**

- **Isolation lives in application code, not the database.** A missing `where tenant_id = ?` is a
  cross-tenant data leak, where schema-per-tenant would have made it physically impossible. The
  entire next section exists to manage exactly this risk.
- **Noisy neighbours.** One tenant's huge table shares indexes and buffer cache with everyone else.
- **Coarser blast radius for per-tenant operations.** Per-tenant export, restore, or
  "delete everything for tenant X" are `DELETE ... WHERE tenant_id = ?` (handled here by
  `cascadeOnDelete` foreign keys) rather than a `DROP SCHEMA`.

For the scale this kit targets — early-stage B2B SaaS — operational simplicity wins decisively. The
"when to outgrow this" note at the end covers the inflection point.

### 2.2 A global scope, applied by a trait, so isolation is the default

**Decision.** Tenant-owned models use the
[`BelongsToTenant`](backend/app/Models/Concerns/BelongsToTenant.php) trait, which:

1. Attaches the [`TenantScope`](backend/app/Models/Scopes/TenantScope.php) global scope via the
   `#[ScopedBy(TenantScope::class)]` attribute, so **every** query gains
   `where tenant_id = <current tenant>` automatically.
2. Hooks `creating` to stamp `tenant_id` from the current context, so writes are tagged correctly
   without the caller passing (or being able to forge) it.

```php
#[ScopedBy(TenantScope::class)]
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::creating(function ($model): void {
            if ($model->tenant_id === null) {
                $model->tenant_id = app(TenantContext::class)->id();
            }
        });
    }
    // ...tenant() relation
}
```

**Why a global scope over per-query filtering.** The alternative — remembering to call
`->where('tenant_id', $tenant)` on every query — fails the moment a developer forgets once, and that
failure is invisible in code review because the _absence_ of a line is what's wrong. A global scope
inverts the default: isolation happens unless you explicitly opt out
(`Model::withoutGlobalScope(TenantScope::class)`), which is a loud, greppable, reviewable act.

**The failure mode if you forget the trait.** This is the sharp edge of row-level tenancy, so be
clear-eyed about it: **if you add a new tenant-owned table and forget `BelongsToTenant` on its
model, queries return every tenant's rows.** There is no exception, no warning — just a silent
cross-tenant leak. The model "works" in single-tenant testing and fails in production.

**What guards against this — two layers, one static and one behavioural.** The first guard is
mechanical: [`EnforceBelongsToTenantRule`](backend/app/PhpStan/Rules/EnforceBelongsToTenantRule.php),
a custom Larastan rule registered in [backend/phpstan.neon](backend/phpstan.neon). It parses the
migration files to find every table that declares a `tenant_id` column, maps each Eloquent model to
its table, and **fails analysis** if a model on a tenant table does not use `BelongsToTenant`.
Because PHPStan (Larastan, level 6) is a blocking CI gate, an unscoped tenant model can no longer be
merged — the guarantee moved from "we wrote a test that exercises it" to "CI mechanically refuses to
merge an unscoped tenant table." A model that legitimately carries `tenant_id` without being
request-scoped opts out _explicitly_ with the
[`#[NotTenantScoped]`](backend/app/Models/Attributes/NotTenantScoped.php) attribute and a stated
reason (today only [`RefreshToken`](backend/app/Models/RefreshToken.php), whose lookup runs before
any tenant context exists); pivots such as `TenantUser` are excluded as join tables. The opt-out is
a loud, greppable, reviewable act — not a silent omission. (It reads migrations rather than a live
schema, so it needs no database — the CI static-analysis job has none.)

**The second guard is behavioural.** The
[`TenantIsolationTest`](backend/tests/Feature/TenantIsolationTest.php) feature suite asserts from the
outside that one tenant cannot see, read, mutate, or assign across the boundary. The static rule
proves the trait is _present_; the test proves isolation actually _holds_ at runtime against real
queries (§2.10). They catch different classes of mistake, which is why both exist.

### 2.3 `TenantContext`: a request-scoped singleton as the single source of truth

**Decision.** The active tenant is held in
[`TenantContext`](backend/app/Support/TenantContext.php), a singleton bound in the container and
populated by the `ResolveTenant` middleware. The global scope, the authorization policies, and even
DTO validation rules all read the tenant from this one place.

**Why a container singleton over a static or a global.**

- **Testability.** It can be rebound or reset between tests; a `static` property leaks across cases.
- **One writer, many readers.** Exactly one component (the middleware) sets it; everything else reads
  it. That keeps the "where did the tenant come from?" question to a single answer.
- **No implicit globals threaded through signatures.** Scopes, policies, and rules resolve it from
  the container instead of every method taking a `$tenant` argument.

**The long-running-worker trap, and how it's handled.** A singleton is per-container, and in
long-lived processes (queue workers, Octane) the container can outlive a single request. The
`JwtGuard` re-binds itself to the current request (`setRequest`) and clears its cached user/tenant on
each resolution, so a tenant resolved for request _A_ cannot bleed into request _B_. The deliberate
design choice is that **`TenantContext` is empty by default** — outside an HTTP request (console,
queues, seeders, tinker) `TenantScope` becomes a no-op rather than guessing a tenant. That is why
cache invalidation (§2.7) takes the tenant id _explicitly_ from the mutated model rather than reading
the context.

### 2.4 404, not 403, for cross-tenant resource access — but 403 at the door

This distinction trips people up, so it's worth being precise: **the kit returns both codes, for
different failures.**

**403 — "you can't be here at all" (the door).** The
[`ResolveTenant`](backend/app/Http/Middleware/ResolveTenant.php) middleware returns `403` when the
access token carries no `tid` claim, or when the authenticated user is not a member of the tenant the
token names. This is an authentication/membership failure: the request never establishes a valid
tenant context, so there is nothing to scope queries to.

```php
if ($user === null || $tenantId === null) {
    abort(403, 'No tenant context in token.');
}
if (! $user->belongsToTenant($tenantId)) {
    abort(403, 'You are not a member of this organization.');
}
```

**404 — "that resource does not exist (for you)".** Once a valid member is inside their tenant, if
they request a _resource id that belongs to another tenant_, they get `404`. They don't get `403`,
because `403` would confirm the resource exists. The mechanism is elegant: the `TenantScope` has
already filtered the row out of every query, so `Project::findOrFail($id)` simply finds nothing and
404s — no explicit cross-tenant check is needed in the controller.

**Why 404 over 403 here.** A `403` on a foreign resource is an enumeration oracle: an attacker
walking `/api/projects/1..N` learns which ids exist by reading status codes. Returning a uniform
`404` for "doesn't exist" and "exists but isn't yours" closes that side channel — the responses are
indistinguishable.

**Trade-off.** A legitimate member who fat-fingers an id they _do_ own also sees `404`. That's the
correct and acceptable cost: from the API's perspective, a resource you can't see is
indistinguishable from one that isn't there, and that's exactly the property we want.

Verified by [`TenantIsolationTest`](backend/tests/Feature/TenantIsolationTest.php): cross-tenant
read/update/delete of projects and tasks all assert `404`, while a token for a tenant the user has
left asserts `403`.

### 2.5 Stateless JWT access tokens + rotating opaque refresh tokens

**Decision.** Authentication is fully headless and custom-built on `firebase/php-jwt` — no Sanctum,
Passport, Fortify, or session login. See [app/Services/TokenService.php](backend/app/Services/TokenService.php)
and [config/jwt.php](backend/config/jwt.php).

- **Access token:** a short-lived (15 min) HS256 JWT carrying `sub` (user) and `tid` (tenant).
  Stateless — verified by signature alone, no database lookup per request.
- **Refresh token:** a long-lived (30 day) opaque 64-char random string. Only its **SHA-256 hash**
  is stored; the plaintext exists only in the client. Rotated on every use.

**Why split the two.**

- **Stateless access = cheap requests.** The hot path (every authenticated API call) does zero
  database work for auth — it just verifies an HMAC signature. That's the whole point of a short TTL
  JWT.
- **Stateful refresh = revocability.** The price of statelessness is that you can't revoke an access
  token before it expires. Pairing it with a _stateful_ refresh token gives you a revocation point
  (logout, theft response) without putting a DB hit on every request — only on the infrequent
  refresh.

**Refresh-token theft detection (token families).** Every refresh token belongs to a `family_id`.
On rotation the old token is revoked and a new one is issued _in the same family_. If a token that
was **already rotated** is presented again, that means two parties hold tokens from one lineage —
i.e. one was stolen. The service treats reuse as theft and revokes the **entire family**, logging
both the attacker and the victim out and forcing a fresh login:

```php
if ($token->isRevoked()) {
    $this->revokeFamily($token->family_id);
    throw new InvalidRefreshTokenException('Refresh token reuse detected; session revoked.');
}
```

Rotation runs inside a `lockForUpdate()` transaction so two concurrent refreshes can't both succeed
(the second sees the row already revoked).

**Trade-offs you accept.**

- **An access token cannot be revoked before its TTL.** A compromised access token is valid until it
  expires. This is mitigated, not eliminated, by the 15-minute lifetime — short enough to bound
  exposure, long enough to avoid refreshing on every other request.
- **The tenant is baked into the token (`tid`).** A user who belongs to multiple tenants holds a
  token scoped to _one_ of them; switching tenants means obtaining a new token for the other tenant.
  This keeps the scope unambiguous (a request is always for exactly one tenant) at the cost of a
  re-issue on tenant switch.
- **Symmetric signing (HS256).** Simpler than RS256 and fine for a single backend that both issues
  and verifies. If you later need third parties to verify tokens without the signing secret, move to
  asymmetric keys.

### 2.6 One Redis, three roles — and what that costs operationally

**Decision.** A single Redis instance backs three subsystems
([.env.example](backend/.env.example), [compose.yaml](compose.yaml)):

1. **Cache** (`CACHE_STORE=redis`) — application + tenant-aware cache (§2.7).
2. **Queue** (`QUEUE_CONNECTION=redis`) — the backing store for the `queue:work` worker.
3. **Broadcast** (`BROADCAST_CONNECTION=redis`) — the pub/sub transport for real-time events.

(Sessions default to the `database` driver, not Redis, so the API stays stateless.)

**Why consolidate.** For a small deployment, running one Redis instead of three is dramatically
simpler: one container, one health check, one thing to monitor and secure. Redis is genuinely good at
all three jobs, and at low volume they don't meaningfully contend.

**The operational implications you must understand.**

- **Single point of failure.** If Redis is down, you lose caching _and_ job processing _and_
  broadcasting simultaneously. The blast radius of one outage is three subsystems.
- **Shared memory and eviction pressure.** Cache, queue, and pub/sub compete for the same memory.
  The dangerous interaction: an aggressive `maxmemory-policy` (e.g. `allkeys-lru`) chosen to keep the
  _cache_ healthy can evict **queued jobs**, silently dropping work. Cache data is disposable; queued
  jobs are not. Don't let one eviction policy govern both.
- **`FLUSHDB`/`FLUSHALL` is a foot-gun.** "Clear the cache" can wipe pending jobs if they live in the
  same logical database. The kit mitigates this by putting cache on its own logical DB
  (`config/database.php` defines separate `default` and `cache` Redis connections), but the operator
  must respect that separation.

**Production guidance.** Keep the logical-DB separation already configured. As volume grows, split
the queue (and ideally broadcast) onto their **own Redis instance** with persistence/eviction tuned
for durability, leaving the cache instance free to evict aggressively. That single change removes the
most dangerous coupling.

**Why no Horizon.** The kit deliberately runs a plain `php artisan queue:work` worker
([compose.yaml](compose.yaml)) rather than Laravel Horizon. The worker uses bounded restarts
(`--max-time`, `--max-jobs`) to cap memory growth and a `--timeout` aligned with the Redis
`retry_after` so an in-flight job is never retried while still running. Horizon adds a supervisor,
dashboard, and metrics — real value at scale, but also a Redis-backed dashboard and more moving
parts. For a starter kit, a transparent one-line worker you can fully reason about is the better
default. Adding Horizon later is a non-breaking change.

### 2.7 Tenant-aware caching: SWR with dual-tag invalidation

**Decision.** Read-heavy list endpoints go through
[`TenantCache`](backend/app/Support/Cache/TenantCache.php), and model
[observers](backend/app/Observers/) invalidate on writes.

**Why every key and tag embeds the tenant id.** In a single-database design, the cache is just
another place a cross-tenant leak can happen. Keying entries as `t:{tid}:{resource}:{suffix}`
guarantees tenant A can never read tenant B's cached payload, mirroring the database-level scope.

**Why two tags per entry (broad + narrow).** Each entry is tagged with both `t:{tid}` (the whole
tenant) and `t:{tid}:{resource}` (e.g. just projects). That lets a project mutation flush _only_
project caches for that tenant — not the tenant's tasks, members, or everything else — while still
allowing a full `flushTenant()` for offboarding.

**Why stale-while-revalidate (`flexible`, 5 min fresh / 15 min stale).** Under a cache stampede
(many concurrent misses on a hot key), SWR serves slightly stale data while a single request
recomputes, instead of letting every request hammer the database at once. The deliberate cost is a
bounded staleness window: for up to 15 minutes a reader may see data a few minutes old. For list
views that is an excellent trade; observers also flush on every mutation, so stale windows are the
exception, not the rule.

**Why invalidation takes the tenant id explicitly.** `remember()` reads the tenant from
`TenantContext` (it always runs in a request), but `flush()` takes the tenant id **from the mutated
model**. That's because observers also fire from queues and console where no request tenant exists
(see §2.3) — relying on the context there would invalidate the wrong tenant, or none.

**Trade-off.** Tagged caching requires a tag-capable store (Redis), so the cache driver is not
freely swappable to `file`/`database` without losing per-resource invalidation.

### 2.8 DTOs (spatie/laravel-data) as the API contract

**Decision.** Request validation and response shaping both live in `App\Data\*` objects (e.g.
`ProjectData`, `TaskData`, `RegisterData`, `TokenPairData`).

**Why.** A single typed object defines what comes in and what goes out, so the contract is one
artifact instead of a FormRequest plus an API Resource plus a hand-written array. Types are explicit,
IDE-navigable, and analyzable by PHPStan.

**Where it reinforces tenancy.** Validation rules can be tenant-aware. For example, a task's
`assignee_id` is validated against `tenant_user` _scoped to the current tenant_, so you cannot assign
work to a user from another organization — the tenancy boundary is enforced at the validation layer,
not just the query layer.

### 2.9 RBAC: roles on the membership, resolved through the tenant context

**Decision.** Authorization is policy-based
([app/Policies/](backend/app/Policies/)) with roles stored **on the membership pivot**, not on the
user. The [`TenantRole`](backend/app/Enums/TenantRole.php) enum (Owner / Admin / Member) exposes
`canManageTenant()`, and the [`ResolvesTenantRole`](backend/app/Policies/Concerns/ResolvesTenantRole.php)
trait resolves the acting user's role _in the current tenant_ from `TenantContext`.

**Why role-per-membership, not per-user.** A user can belong to multiple tenants with different
standing — Owner of their own org, Member of a client's. A global `role` column can't express that.
Storing the role on `tenant_user` makes "what can this user do?" a question that's only answerable
_within a tenant_.

**The capability split.** Members can read and write domain data (projects, tasks); only Owners and
Admins can manage the tenant (membership, destructive project deletes) via `canManageTenant()`. The
member-management flow also protects the **last owner** from being demoted or removed, so a tenant
can never be orphaned without an administrator.

### 2.10 Quality gates, and why the isolation test is the keystone

**Decision.** CI ([.github/workflows/ci.yml](.github/workflows/ci.yml)) blocks merges on three gates:

- **Tests with ≥ 80% coverage** (`php artisan test --coverage --min=80`).
- **Static analysis** (Larastan / PHPStan level 6).
- **Code style** (Pint).

**Why coverage _and_ static analysis.** They catch different classes of bug. PHPStan proves the code
is type-consistent and the wiring is sound; it cannot prove that _tenant isolation holds_, because
isolation is a runtime, data-dependent property. Only an end-to-end test that creates two tenants and
tries to cross the line can assert that.

That makes [`TenantIsolationTest`](backend/tests/Feature/TenantIsolationTest.php) the keystone of the
whole design. It is the executable specification of §2.1–2.4: it asserts a member sees only their
tenant's projects; that cross-tenant reads/updates/deletes return `404`; that a `tenant_id` smuggled
into a request body cannot override the resolved tenant; that an assignee from another tenant is
rejected; and that a token for a tenant the user has left returns `403`. If you change anything about
the tenancy model, this is the test that tells you whether you broke it.

---

## 3. Trade-offs summary & when to outgrow this kit

The kit optimizes for **operational simplicity at small-to-medium scale**. Each simplifying choice
has a known graduation point:

| Decision                        | Good while…                             | Outgrow it when…                                                               | Move to…                                           |
| ------------------------------- | --------------------------------------- | ------------------------------------------------------------------------------ | -------------------------------------------------- |
| Row-level isolation (§2.1)      | Tenants share manageable data volumes   | A tenant demands physical isolation (compliance) or one tenant dwarfs the rest | Schema-per-tenant, or a dedicated DB for whales    |
| Test-only scope guard (§2.2)    | Team is small and disciplined           | New tenant tables are added frequently by many hands                           | A custom Larastan rule enforcing `BelongsToTenant` |
| One Redis, three roles (§2.6)   | Low queue + cache volume                | Cache eviction threatens queued jobs, or one outage is too costly              | Split Redis instances; separate eviction policies  |
| Plain `queue:work` (§2.6)       | Few queues, simple jobs                 | You need supervision, autoscaling, and metrics                                 | Laravel Horizon                                    |
| `tid` baked into the JWT (§2.5) | Users rarely switch tenants mid-session | Frequent tenant switching is a core UX                                         | A tenant-switch endpoint that re-issues tokens     |

---

## 4. Security model summary

| Layer                     | Mechanism                                                                                    | Enforced in                                                                                                                          |
| ------------------------- | -------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------ |
| Authentication            | HS256 JWT access token, signature-verified, 15 min TTL                                       | [JwtGuard.php](backend/app/Auth/JwtGuard.php), [TokenService.php](backend/app/Services/TokenService.php)                             |
| Session continuity        | Opaque refresh token, SHA-256 hashed at rest, rotated per use, family-based theft revocation | [TokenService.php](backend/app/Services/TokenService.php)                                                                            |
| Tenant entry (membership) | `403` if no `tid` or not a member                                                            | [ResolveTenant.php](backend/app/Http/Middleware/ResolveTenant.php)                                                                   |
| Tenant data scoping       | Global `where tenant_id = ?` on every query; `404` on foreign resources                      | [TenantScope.php](backend/app/Models/Scopes/TenantScope.php), [BelongsToTenant.php](backend/app/Models/Concerns/BelongsToTenant.php) |
| Write tagging             | `tenant_id` stamped from context on create; body `tenant_id` ignored                         | [BelongsToTenant.php](backend/app/Models/Concerns/BelongsToTenant.php)                                                               |
| Authorization             | Role-per-membership policies; Owner/Admin vs Member; last-owner protection                   | [app/Policies/](backend/app/Policies/), [TenantRole.php](backend/app/Enums/TenantRole.php)                                           |
| Input validation          | Tenant-scoped rules (e.g. assignee must be a tenant member)                                  | [app/Data/](backend/app/Data/)                                                                                                       |
| Cache isolation           | Tenant id embedded in every cache key and tag                                                | [TenantCache.php](backend/app/Support/Cache/TenantCache.php)                                                                         |
| Regression guard          | End-to-end isolation feature tests, 80% coverage gate, PHPStan, Pint                         | [TenantIsolationTest.php](backend/tests/Feature/TenantIsolationTest.php), [ci.yml](.github/workflows/ci.yml)                         |

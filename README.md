# Laravel SaaS Kit

A production-minded starter kit for building **multi-tenant B2B SaaS** on Laravel 13. It ships an
API-first backend with headless JWT authentication, row-level tenant isolation enforced by default,
role-based access control, and a small Projects → Tasks domain you can replace with your own.

It is intentionally not a do-everything boilerplate. It makes a handful of strong architectural
choices and explains every one of them in [ARCHITECTURE.md](ARCHITECTURE.md) — read that to
understand the *why* behind what you see here.

## Features

- **Headless JWT auth** — stateless 15-minute access tokens plus opaque, rotating refresh tokens
  with token-family theft detection (reuse revokes the whole session). No Sanctum/Passport/Fortify.
- **Row-level multi-tenancy** — a global Eloquent scope silently constrains every query to the
  current tenant, so isolation is the default rather than something you remember to add.
- **Role-based access control** — Owner / Admin / Member roles stored per membership, enforced by
  policies, with last-owner protection.
- **Tenant-aware caching** — read-through cache with stale-while-revalidate and per-resource
  invalidation, keyed by tenant so the cache can't leak across organizations.
- **Async mail** — welcome email queued via Redis after registration.
- **Quality gates in CI** — 80% test-coverage minimum, PHPStan (Larastan) level 6, and Pint.

## Tech stack

| Layer | Choice |
| --- | --- |
| Framework | Laravel 13 (PHP 8.3+, runs on 8.4) |
| Database | PostgreSQL 17 |
| Cache / Queue / Broadcast | Redis 7 |
| Web server | Nginx → php-fpm (FastCGI) |
| Auth | Custom JWT (`firebase/php-jwt`, HS256) |
| DTOs / validation | `spatie/laravel-data` |
| Tooling | PHPUnit, PHPStan + Larastan, Pint |
| Local orchestration | Docker Compose |

## Quick start (Docker)

The repo is wired for Docker Compose. From the project root:

```bash
# 1. Configure the backend environment
cp backend/.env.example backend/.env

# 2. Build and start the stack (backend, nginx, postgres, redis, queue worker)
docker compose up -d --build

# 3. Generate keys and run migrations inside the app container
docker compose exec backend php artisan key:generate
docker compose exec backend php artisan migrate

# 4. Build front-end assets (Vite + Tailwind)
docker compose exec backend npm install && docker compose exec backend npm run build
```

The API is then served at **http://localhost:9000** (Nginx publishes container port 80 on host
`9000` — see [compose.yaml](compose.yaml)).

### Environment variables that matter

The defaults in [backend/.env.example](backend/.env.example) already point at the Compose service
names (`postgres`, `redis`). The ones to be deliberate about:

| Variable | Purpose | Note |
| --- | --- | --- |
| `APP_KEY` | App encryption key | Set via `php artisan key:generate` |
| `JWT_SECRET` | HMAC secret for signing access tokens | Falls back to `APP_KEY`, but **set a dedicated value in production** |
| `JWT_ACCESS_TTL` | Access-token lifetime (seconds) | Default `900` (15 min) |
| `JWT_REFRESH_TTL` | Refresh-token lifetime (seconds) | Default `2592000` (30 days) |
| `DB_*` | PostgreSQL connection | Defaults match the `postgres` service |
| `REDIS_HOST` | Redis host | Defaults to the `redis` service |
| `CACHE_STORE` / `QUEUE_CONNECTION` / `BROADCAST_CONNECTION` | All `redis` | One Redis, three roles — see [ARCHITECTURE.md §2.6](ARCHITECTURE.md) |

## Local development

Work happens in [backend/](backend/). Common tasks (run inside the container, or locally if you have
the PHP toolchain):

```bash
php artisan test                 # run the PHPUnit suite
php artisan test --coverage --min=80   # the gate CI enforces
vendor/bin/phpstan analyse       # static analysis (Larastan, level 6)
vendor/bin/pint                  # auto-format; `pint --test` to check only
```

For an all-in-one local loop without Docker, `composer dev` runs the dev server, a queue listener,
log tailing (Pail), and Vite concurrently. The `queue` service in Compose already runs a worker, so
under Docker you don't need a separate one.

## API reference

Public endpoints (no auth):

| Method | Path | Purpose |
| --- | --- | --- |
| `POST` | `/api/register` | Create an organization + owner user, returns a token pair |
| `POST` | `/api/login` | Exchange credentials for a token pair |
| `POST` | `/api/refresh` | Rotate a refresh token for a fresh token pair |

Authenticated endpoints (require `Authorization: Bearer <access_token>`; the token's `tid` claim
pins the tenant):

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/api/me` | Current user |
| `POST` | `/api/logout` | Revoke the current refresh token |
| `GET·POST` | `/api/projects` | List / create projects |
| `GET·PUT·DELETE` | `/api/projects/{project}` | Show / update / delete a project |
| `GET·POST` | `/api/projects/{project}/tasks` | List / create tasks in a project |
| `GET·PUT·DELETE` | `/api/projects/{project}/tasks/{task}` | Show / update / delete a task |
| `GET` | `/api/members` | List tenant members |
| `POST` | `/api/members` | Add an existing user to the tenant (Owner/Admin) |
| `PATCH` | `/api/members/{member}` | Change a member's role (Owner/Admin) |
| `DELETE` | `/api/members/{member}` | Remove a member (Owner/Admin) |

> Cross-tenant requests behave as designed: requesting another tenant's resource returns **404**
> (not 403), and a token without a valid membership returns **403**. See
> [ARCHITECTURE.md §2.4](ARCHITECTURE.md).

### Example flow

```bash
# Register → you get an access_token + refresh_token
curl -s -X POST http://localhost:9000/api/register \
  -H 'Content-Type: application/json' \
  -d '{"organization_name":"Acme","name":"Ada","email":"ada@acme.test","password":"password123"}'

# Use the access token for tenant-scoped calls
curl -s http://localhost:9000/api/projects \
  -H 'Authorization: Bearer <access_token>'

# When the access token expires, rotate the refresh token for a new pair
curl -s -X POST http://localhost:9000/api/refresh \
  -H 'Content-Type: application/json' \
  -d '{"refresh_token":"<refresh_token>"}'
```

## Project layout

```
.
├── compose.yaml            # backend, nginx, postgres, redis, queue worker
├── nginx/                  # Nginx site config
├── .github/workflows/      # CI: tests + coverage gate, PHPStan, Pint
├── ARCHITECTURE.md         # why every decision was made (read this)
└── backend/                # the Laravel application
    ├── app/
    │   ├── Auth/           # JwtGuard
    │   ├── Services/       # TokenService, RegistrationService
    │   ├── Support/        # TenantContext, tenant-aware cache
    │   ├── Models/         # + Concerns/BelongsToTenant, Scopes/TenantScope
    │   ├── Http/Middleware # ResolveTenant
    │   ├── Policies/       # Project/Task/Member authorization
    │   ├── Data/           # spatie/laravel-data DTOs (API contract)
    │   └── Observers/      # cache invalidation on writes
    ├── routes/api.php      # the endpoints above
    └── tests/Feature/      # incl. TenantIsolationTest (the keystone test)
```

## Documentation

- **[ARCHITECTURE.md](ARCHITECTURE.md)** — the design decisions and their trade-offs: row-level vs
  schema-per-tenant tenancy, the global-scope approach and its failure mode, 404-vs-403, JWT +
  refresh rotation, Redis's three roles, caching, and RBAC.

## License

MIT.

# SellerBoost funnel migration lab

This repository is a runnable migration story: procedural PHP and MySQL remain available at `/legacy/`, while selected funnel screens are served by a React, TypeScript and Vite SPA at `/app/`. Both interfaces use the same `leads` table and PHP session, so a lead created in one generation can be inspected in the other.

SellerBoost is deliberately a demo: a free checklist and a simulated $29 Pro toolkit. No card details are collected, no payment provider is called, no email is sent, and no digital product is delivered.

## Run the two phases

Prerequisites are Docker with Compose, and Node 22 only if running frontend commands on the host. Optionally start from local defaults with `cp .env.example .env`. The local web port defaults to `18081` and binds to `127.0.0.1` intentionally, avoiding exposure and common host-port collisions; the container still listens on `8080`. MySQL data lives in named `db-data`, and PHP sessions live in named `sessions`; both survive ordinary web rebuilds and flag rollbacks. The schema is applied when the database is first initialized.

### Legacy-only checkout

```sh
BUILD_TARGET=legacy MIGRATE_CHECKOUT=0 docker compose up -d --build
```

Open [http://127.0.0.1:18081/legacy/](http://127.0.0.1:18081/legacy/). Signup and the fake payment page are `/legacy/signup.php` and `/legacy/payment.php`. The default admin page is `/legacy/admin.php`; use Basic Auth `admin` / `local-admin-password` unless overridden with environment variables.

### Hybrid / migrated checkout

```sh
BUILD_TARGET=hybrid MIGRATE_CHECKOUT=1 docker compose up -d --build
```

Open [http://127.0.0.1:18081/app/](http://127.0.0.1:18081/app/). React owns landing, signup and checkout status while `/legacy/` remains available. With `MIGRATE_CHECKOUT=0`, both free and paid signups use legacy status routes; with `1`, direct legacy checkout requests redirect to React and React checkout sends the browser to legacy when the flag is off. Recreate `web` after changing the flag.

Override defaults with `.env` (see [.env.example](./.env.example)): `APP_PORT`, database credentials, `ADMIN_USER`, `ADMIN_PASSWORD`, `BUILD_TARGET`, and `MIGRATE_CHECKOUT`.

## Architecture

The `web` container runs PHP 8.3's built-in server behind `public/router.php`. It dispatches `/legacy/*` to server-rendered PHP, `/api/*` to a thin JSON API, and `/app/*` to Vite static output. MySQL 8.4 uses [`docker/schema.sql`](./docker/schema.sql). Node 22 builds the bundle into `public/app` for the hybrid image.

`src/bootstrap.php` is the shared seam: HTTP-only, SameSite=Lax sessions, a random browser owner, CSRF token, PDO prepared queries, validation, and checkout operations. `sessions` preserves session files across web recreation and flag rollback. This is still a single-host demo; a multi-host deployment needs a shared session backend and coordinated cookie/secret configuration.

The API exposes `GET /api/session`, `GET|POST /api/leads`, `GET|PATCH|DELETE /api/leads/{id}`, and `POST /api/leads/{id}/intent` and `/pay`. Mutations send `X-CSRF-Token`. For curl:

```sh
curl -c /tmp/sellerboost.cookies http://127.0.0.1:18081/api/session
curl -b /tmp/sellerboost.cookies -c /tmp/sellerboost.cookies -H 'Content-Type: application/json' -H 'X-CSRF-Token: <csrf-from-first-response>' -d '{"name":"Curl Seller","email":"curl@example.com","plan":"free"}' http://127.0.0.1:18081/api/leads
curl -b /tmp/sellerboost.cookies http://127.0.0.1:18081/api/leads
```

## Verification

```sh
docker compose --profile test run --rm --no-deps --build tests
(cd frontend && npm ci && npm run build && npx playwright install chromium)
PLAYWRIGHT_BASE_URL=http://127.0.0.1:18081 npm --prefix frontend run test:e2e
```

Run the PHPUnit command after starting the selected legacy or hybrid `web` service from the preceding sections. `--no-deps` keeps the test container from unexpectedly recreating `web` with Compose defaults (`MIGRATE_CHECKOUT=0`) while you are checking the flag 1 setup. For local frontend development, keep the Compose backend running and use `(cd frontend && npm run dev)`; Vite proxies API calls to the configured backend during development. The Playwright command requires the browser binary; the install command above provisions Chromium.

PHPUnit covers legacy characterization, the session bridge, API CRUD and ownership, CSRF/admin protection, and idempotent payment. Playwright covers React and legacy entry points. The current verification run completed npm install/build, `npm audit` with zero findings, Docker config/build, PHPUnit for both flags (4 tests; 45 assertions at flag 0 and 41 at flag 1), and six Playwright tests per flag (desktop and mobile projects). Keep rerunning these checks for future changes; they are local demo evidence, not production rollout evidence.

## Rollback and limits

Rollback explicitly with `MIGRATE_CHECKOUT=0 docker compose up -d --build --force-recreate web`. Existing rows and session files remain in `db-data` and `sessions`. Do not use `docker compose down -v` for rollback; it removes those volumes and is only an intentional demo reset.

The built-in PHP server and Compose defaults are local-demo infrastructure. Production would need a real web/process server, TLS, secret management, backups, real authentication, durable shared sessions, and a payment provider with webhooks. See [`MIGRATION.md`](./MIGRATION.md).


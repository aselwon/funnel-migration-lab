# SellerBoost migration narrative

This is an interview-sized, intentionally reconstructed legacy example rather than a report of a production migration. The constraint is continuity: keep the old PHP journey runnable, introduce a narrow API around existing data, then move selected screens behind a flag. The migration remains observable and reversible.

## Before

The original flow is procedural PHP under `public/legacy/`: landing, server-rendered signup, fake payment, and admin list. Sessions, validation, SQL, escaping, redirects, and checkout behavior are close together. It works, but page changes and data access are coupled.

The `leads` table stores owner, name, email, plan, status, and a simulated intent. A browser owns rows through a random PHP session identifier; there are no customer accounts. The admin page uses HTTP Basic Auth, while funnel users have only their PHP session identity.

## Extraction seam

The first step keeps the database and domain rules in place. `src/bootstrap.php` provides prepared PDO queries, validation, ownership checks, CSRF verification, intent creation, and payment transitions. `src/api.php` exposes those operations as JSON instead of duplicating them in a second backend.

The API covers session bootstrap, lead creation/listing, one-lead reads and updates, demo cleanup deletion, and checkout operations. React sends same-origin credentials and the session CSRF token. Legacy and React therefore share browser session and data while the boundary stays explicit.

## After

React/Vite owns landing, signup, and checkout status under `/app/`. PHP still serves the bundle and remains the server entry point. `MIGRATE_CHECKOUT=1` sends newly created free and paid leads to React checkout status; direct legacy checkout requests also redirect to React. With `0`, React checkout returns the browser to `/legacy/payment.php?id={id}` and new signups use legacy status. Legacy remains linked and runnable for comparison and rollback.

The SPA is not a second backend: the thin PHP API enforces validation, owner scoping, CSRF, and checkout state transitions. Shared MySQL means a lead created in either interface appears in the other and in legacy admin.

## Delivery sequence

1. Characterize legacy free/paid signup, escaping, invalid input, CSRF, redirects, and payment.
2. Extract the shared database/domain seam without changing table ownership or status meanings.
3. Add JSON API and session bridge; verify cross-interface visibility and session ownership isolation.
4. Build React screens against that API while keeping old routes available.
5. Enable the flag in a controlled environment and observe status transitions before expanding traffic.
6. Roll back with `MIGRATE_CHECKOUT=0 docker compose up -d --build --force-recreate web`; data and persisted session files remain.

The invariants to preserve during review are concrete: `free` leads start `free`, paid leads start `pending`, successful payment ends `paid`; a lead's plan cannot change after signup; the USD 29 amount is server-owned; invalid requests return stable JSON error codes; and a browser session is an owner boundary, not an account identity.

## Trust boundaries and controls

The browser is untrusted. PHP validates fields on both paths, escapes legacy HTML, uses prepared SQL, and scopes lead operations by session owner. Mutating API calls require the `/api/session` CSRF token; legacy forms carry the same token. Checkout requires the server-stored intent, so the client cannot choose an arbitrary paid transition.

Admin is a separate operator boundary protected by Basic Auth, with credentials from environment variables. This is local-demo protection, not production identity. The random browser owner is not proof of a person or email account. The `sessions` volume preserves PHP session files across ordinary web recreation, but multiple hosts would require a shared session backend and coordinated cookie/secret configuration.

## Risks and mitigations

The main risk is semantic drift between checkout generations. Both currently call shared functions, and tests cover status transitions and idempotent intents. Preserve `free`, `pending`, and `paid` meanings or add a data migration first. A proposed rollout check is to compare these invariants and error responses for both flag values, then inspect the shared admin list after each flow; this repository does not claim production rollout results.

Flag rollback affects new routing decisions; a React page already open can finish through the API after the flag changes. Use a short drain or incident procedure. Never remove `db-data` or `sessions` during rollback: `docker compose down -v` is a destructive demo reset.

The API uses browser sessions rather than durable accounts. Session expiry, cookie policy, multiple tabs, and reverse-proxy HTTPS need production testing. Replace Basic Auth, store secrets properly, and add payment verification, webhook idempotency, reconciliation, and audit logging.

## Deployment and cache caveats

The hybrid target copies Vite output into `public/app`; changing a flag does not rebuild frontend code. Rebuild and recreate `web` for code or environment changes. Vite assets are content-hashed, but HTML/proxy caches must not serve an old app shell. API responses use `Cache-Control: no-store` because they are session-specific.

The PHP built-in server and Compose defaults are local-demo infrastructure. Production needs a real process/web server, TLS, health checks, backups, migrations, logs/metrics, resource limits, and a rollout that keeps old routes available until the new path is proven. This lab intentionally stops before real products, payments, email, or customer authentication.

## Five-step reviewer acceptance walk-through

1. Run with `BUILD_TARGET=legacy MIGRATE_CHECKOUT=0`, create one free and one paid lead, and complete the paid simulated payment on the legacy page.
2. Open legacy admin and confirm both rows and their final statuses.
3. Recreate with `BUILD_TARGET=hybrid MIGRATE_CHECKOUT=1`, create free and paid leads from React, and complete the paid simulated payment.
4. Open legacy admin again and confirm the new rows are visible in the same database; open a direct legacy checkout URL and confirm the flag routes it to React.
5. Roll back with `MIGRATE_CHECKOUT=0 docker compose up -d --build --force-recreate web`, open a React checkout URL, and confirm it returns to legacy while prior rows remain.

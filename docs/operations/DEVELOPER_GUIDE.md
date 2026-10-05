# Developer guide

For engineers maintaining the iEDIFY Africa platform. Read `AGENTS.md` and
`docs/architecture/FOUNDATION.md` first; this guide covers the working
conventions.

## Shape

- PHP 8.4+ modular monolith, no framework. PSR-4 `IEdify\` → `app/`.
- `app/Core` — Config, Connection/Transaction/MigrationRunner, Http (Kernel,
  Controller, HttpError), Security (Actor, Policy, Csrf, RateLimiter,
  SecretBox, SecurityHeaders), Jobs (Outbox/OutboxProcessor), Mail, Sms,
  Audit, View (Twig), Clock.
- `app/Modules/<Domain>/` — `routes.php`, `Http/` controllers, `Services/`
  domain services. Modules: Identity, CMS, Programs (applications/learning is
  under Learning + Community), Engagement, Funding, Impact, Partners, Web,
  Operations.
- Persistence is PDO/MySQL only; schema changes are forward-only Phinx
  migrations in `database/migrations`.

## Non-negotiable rules

1. `declare(strict_types=1)` in every PHP file.
2. Business mutations run inside `(new Transaction($pdo))->run(...)`; the
   audit record and any outbox event go in the **same** transaction.
3. No transport call (mail/SMS/HTTP) inside a DB transaction — write an
   outbox event; `outbox:run` delivers it with lease/retry/backoff.
4. Authorization uses `Policy::allows($actor, 'permission.name')` at the
   service layer and route guards (`auth`, `permission`, `privileged`,
   `guest`) at HTTP. Both are required — a route guard alone is not object
   authorization.
5. Twig autoescape stays on; rich text passes `Sections` + Symfony
   HtmlSanitizer. Never render untrusted file contents.
6. Money is DECIMAL/string; no float arithmetic. Time stored UTC;
   Africa/Accra for display.
7. Private uploads: allowlisted MIME (server-sniffed), 10 MB cap, SHA-256
   filenames, outside docroot, `/media/{id}` authorization. Public uploads go
   through the media review queue (`public_content` + `pending`).
8. Published data only on public surfaces; targets and actuals are separate
   fields and are never merged in output.

## Adding a feature

1. Migration for the schema (`database/migrations/YYYYMMDDHHMMSS_name.php`,
   `change()` with Phinx builders).
2. Permissions in `app/Modules/Identity/Services/roles.php`; re-run
   `identity:seed-roles` (idempotent upsert).
3. Service with `authorize()` + `Transaction`; thin controller under
   `Http/`; entries in `routes.php`; Twig view in `resources/views`.
4. Integration test (service, real `_test` DB) and a Feature test (HTTP via
   `Kernel`) for any user-facing journey.
5. Update `docs/requirements-evidence.json`, regenerate the matrix
   (`bin/requirements-matrix.php` if present), and `BUILD_STATUS.md`.

## Commands

See `AGENTS.md` for the full list. Day-to-day: `database:migrate`,
`vendor/bin/phpunit`, `vendor/bin/phpstan analyse`, `bin/lint.php`,
`npm run build`, `npm run test:e2e` (needs a free port 8080).

## Outbox events

Emit with a deterministic `event_key` for dedup. Payloads must not contain
secrets — encrypt with `SecretBox` (e.g. invitation tokens). Delivery handlers
live in `OutboxProcessor`; failures retry with backoff then land `failed`,
visible in `/admin/outbox` where staff can requeue.

## Gotchas

- `Config::load($root, true)` requires `.env.test` with `_test` DB and
  `MAIL_LIVE_ENABLED=false`; tests share that DB — use unique keys per run.
- Rate limiting keys on client IP; Feature tests pass `REMOTE_ADDR`.
- The public slug route is a catch-all — register literal routes in
  `Web/routes.php` before it or they'll never match.
- PowerShell: no `&&`; BOM breaks `strict_types` — write files via tooling,
  not `Set-Content -Encoding utf8` (PS5 writes BOM).

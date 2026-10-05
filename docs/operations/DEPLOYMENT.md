# Deployment (cPanel shared hosting)

Target shape: only `public/` is the document root; everything else lives one level above it and is unreachable from the web. If the host forces `public_html`, point it at `public/` or relocate the app outside and symlink — do **not** copy the whole repo into `public_html`.

## Pre-release checklist

1. Run the full gate locally: `bin/console app:doctor`, `database:migrate`, `database:migrate --env=test`, `vendor/bin/phpunit`, `vendor/bin/phpstan analyse`, `bin/lint.php`, `npm run build`, `npm run test:e2e`, `npm audit`, `composer audit`.
2. Build assets (`npm run build`) — `public/build/` must ship with the release; do not rely on the host having Node.
3. Prepare `.env` on the server from `.env.example` with production values. Required decisions: `APP_URL`, `DB_*`, `MAIL_TRANSPORT`/`MAIL_*`, `CONTENT_STORAGE`, `APP_KEY` (generate fresh), `SIGNUP_ENABLED`.
4. Confirm activation gates: live funding (`FUNDING_LIVE_ENABLED=false`), live email (`MAIL_LIVE_ENABLED=false` until the mailbox is authorized) and SMS stay **off** unless an explicit activation decision has been made and documented.
5. Take a backup of the current production database before touching it (`backup:create` on the old build, or a hosting-panel dump).

## Host capacity verification (required before commit to a plan)

Do not assume the package supports the platform. Confirm each item against the
actual McAforo plan and record the measured values — if any fails, document the
requirement and a compatible upgrade route rather than working around it:

| Check | Requirement | How to verify |
|---|---|---|
| PHP version | 8.4+ (8.5 tested) | cPanel PHP selector / `php -v` |
| Extensions | pdo_mysql, sodium, mbstring, intl, openssl, fileinfo, gd or none-safe, json, ctype | `php -m` on the selected CLI version |
| MySQL | 8.0+ (8.4 tested) | `SELECT VERSION();` |
| Document root control | ability to point docroot at `public/` only | hosting panel / symlink support |
| Cron | at least per-minute cron for `outbox:run`, plus daily jobs | cPanel Cron Jobs UI |
| Storage | app dir outside docroot writable (`storage/private`) | quota + path check |
| SMTP | outbound mail allowed on configured port; daily send limits sufficient for transactional + newsletter volume | panel docs; test send to an external address |
| Backups | scheduled off-host backup ability (panel backups or cron `backup:create` + pull) | panel backup options |
| TLS | HTTPS for the domain (Secure cookies required in production) | AutoSSL/Let's Encrypt availability |
| Concurrency | PHP-FPM/LSAPI concurrent-request capacity for expected traffic | plan specification |

If the plan cannot meet the table, the documented minimum is a cPanel-class
host with PHP 8.4+, MySQL 8.0+, real cron and docroot control — shared plans
without cron or docroot control are not sufficient.

## Upload security notes

Uploads are MIME-verified (server-side sniffing, not the client header),
size-limited to 10 MB, restricted to PDF/PNG/JPEG, stored under SHA-256 names
outside the document root, and served only through `/media/{id}` with
`nosniff` and authorization. Public uploads stay `pending` in the media review
queue until approved. SVG and executable formats are rejected at the
allowlist — nothing else can be stored. There is **no antivirus engine on the
target class of shared hosting**: the quarantine/review step is the control.
If the final host offers ClamAV or a scanning API, hook it into
`DocumentStore::store` before the media row is written.

## Release steps

0. Complete the host capacity verification above and record results.
1. Upload the application outside the document root (e.g. `/home/USER/app`), with `public/` mapped as the docroot.
2. Upload `public/build/` from the verified local build.
3. Install dependencies: `composer install --no-dev --optimize-autoloader` (run locally and upload `vendor/` if Composer is unavailable on the host).
4. Create `.env` with production values; `chmod 600`.
5. Ensure writable dirs exist and are private: `storage/private/{content,backups}`, `storage/mail` (dev only), `storage/logs` — all outside `public/`.
6. Run migrations: `php bin/console database:migrate`.
7. Seed/refresh roles: `php bin/console identity:seed-roles`.
8. Provision the first administrator: `php bin/console identity:create-admin` (MFA enrollment is enforced at first sign-in).
9. Configure cron per `CRON.md` (`outbox:run`, `backup:create`, `app:doctor`).
10. Smoke-check: `/health`, `/`, `/sign-in`, one admin journey, one public form POST.
11. Import the content pack once: `php bin/console content:import` (idempotent — safe to re-run; imported items stay unpublished until editorial review).

## Configuration notes

- `APP_ENV=production` switches error display off; logs go to `storage/logs`.
- Private uploads resolve via `CONTENT_STORAGE`; keep it outside the docroot so `/media/{id}` authorization is the only way in.
- `MAIL_TRANSPORT=capture` writes `.eml` files under `MAIL_CAPTURE_DIR` — for development only. Production uses `smtp` with `MAIL_*` settings, after mailbox authorization.
- HTTPS is assumed at the host; the app sends `Secure`/`HttpOnly` cookies — verify the session cookie is flagged in production.

## Post-cutover acceptance

Run the journey checklist from `BUILD_STATUS.md` against the live URL: sign-in + MFA, application submit/review, CMS publish, event registration, partner portal isolation, `/search`, `/sitemap.xml`, `/robots.txt`, `/health`. Record results in the release notes.

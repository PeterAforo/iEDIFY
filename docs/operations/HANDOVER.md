# Handover and training walkthrough

For McAforo (developer/hosting provider) and iEDIFY staff taking ownership of the platform.

## Repository layout

- `app/Core` — config, database, HTTP kernel, security (CSRF, policy, rate limiting, encryption), outbox, mail, audit, view.
- `app/Modules/<Domain>/{routes.php, Http/, Services/}` — one folder per domain (Identity, CMS, Programs, Learning, Community, Funding, Impact, Partners, Engagement, Web, Operations). Route files declare method, path, handler and guard (`auth`, `permission`, `privileged`, `guest`).
- `database/migrations` — sequential, forward-only. `bin/console database:migrate` applies pending ones.
- `resources/views` — Twig templates (`layouts/`, per-module folders, `mail/`, `errors/`). Autoescaping is on; rich text goes through the Symfony sanitizer.
- `resources/assets` + `public/build` — Sass/JS sources and the compiled Vite output that ships to production.
- `storage/private` — private media and backups; never under `public/`.
- `docs/` — architecture, operations runbooks, this guide, requirements matrix and build status.

## Day-one operations

1. `php bin/console app:doctor` — environment/config sanity.
2. `php bin/console database:migrate` then `identity:seed-roles`.
3. `php bin/console identity:create-admin` — first super admin; they must enroll MFA at first sign-in and create further staff by assigning roles (user_roles) until a user-management screen exists.
4. `php bin/console content:import` — idempotent content-pack import; review `/admin/flags` before publishing imported pages.
5. Cron per `CRON.md`: `outbox:run` every minute, `backup:create` daily, `app:doctor` daily.

## Training walkthrough (suggested order)

| Session | Audience | Content |
|---|---|---|
| 1 | All staff | Sign-in, MFA, account security, `/account` hub, notifications and preferences |
| 2 | Editors/publishers | CMS edit→review→publish, editorial flags, revisions/restore, media review (`ADMIN_GUIDE.md` §CMS) |
| 3 | Program team | Programs/intakes/forms, reviewer assignment, decisions, cohort enrollment, exports |
| 4 | Training team | Courses/sessions/attendance, mentoring matches, milestone review |
| 5 | Finance/funding | Rounds, scorecards, awards, disbursement recording (no live transfers) |
| 6 | M&E / partnerships | Indicators/results verify→publish, partner orgs, portal shares and revocation |
| 7 | Moderators | Community groups/reports/moderation log |

## McAforo service arrangement (private — do not publish)

These figures describe McAforo's service arrangement and are **not** public iEDIFY subscription plans:

- Hosting including secure business email: USD 186/year.
- Startup website maintenance/support: USD 240/year, reviewed and negotiated annually.
- Combined: USD 426 once annually, or two USD 213 payments six months apart.
- Website/CMS development and later modules are separate project charges.
- The maintenance allowance covers agreed routine website work; later platform support, major features, SMS charges and payment processing are not automatically included.

Do not build a customer billing/subscription platform from these terms; do not invent service hours, response guarantees or development prices.

## Remaining activation decisions for the client

- Production host plan capacity vs. documented requirements (`DEPLOYMENT.md` checklist).
- Mailbox/MX migration inputs (`ROLLBACK.md`/`DEPLOYMENT.md`).
- Whether to enable public signup, live SMTP sending, live funding rounds, SMS — each is off until explicitly configured and approved.
- Editorial review of imported flags (dated statistics, legal/policy copy, missing hero images).

# iEDIFY Africa

Public website and operational platform for iEDIFY Africa — the International
Entrepreneurial Development and Innovation Fund for Youth in Africa. The site
combines an editorial, CMS-driven public presence (About, Programs, Community,
Impact, Media: News / Publications / Events / Gallery / Resources, Contact)
with member, program-application, partner, and admin functionality.

## Stack

- **Backend:** PHP 8.4+ (strict types, PSR-4 `IEdify\` → `app/`), no framework —
  Symfony HttpFoundation, FastRoute, Phinx migrations, PHPMailer
- **Templates:** Twig (auto-escaped), `resources/views/`
- **Frontend:** Vite + SCSS (`resources/assets/`), Alpine.js (CSP build),
  vanilla JS motion/lightbox, Chart.js for impact charts
- **Database:** MySQL 8.4 via PDO
- **Testing:** PHPUnit (Unit/Integration/Feature), PHPStan, Playwright

## Repository layout

| Path              | Purpose                                             |
|-------------------|-----------------------------------------------------|
| `app/`            | Core framework + feature modules (Web, CMS, Community, Programs, Impact, Partners, …) |
| `bootstrap/`      | Application wiring                                  |
| `config/`         | Environment-driven configuration                    |
| `database/`       | Phinx migrations                                    |
| `docs/`           | Master build prompt, build status, requirements matrix |
| `public/`         | Web root — the only publicly served directory       |
| `resources/`      | Twig views, SCSS/JS sources                         |
| `storage/`        | Private content, logs, sessions, cache (gitignored) |
| `tests/`          | PHPUnit suites                                      |

## Prerequisites

Development runs on an isolated, gitignored `.runtime/` toolchain so nothing
touches existing XAMPP/MySQL installations:

- `.runtime/php/php.exe` — PHP 8.5
- `.runtime/composer.phar` — Composer 2.x
- Portable MySQL 8.4 on `127.0.0.1:3307`
- Node `>=22.12 <23 || >=24 <25`

## Setup

```powershell
& '.\.runtime\php\php.exe' '.\.runtime\composer.phar' install
copy .env.example .env   # then fill in local values (never commit .env)
& '.\.runtime\php\php.exe' '.\bin\console' database:migrate
npm ci
npm run build
```

## Development server

```powershell
& '.\.runtime\php\php.exe' -S 127.0.0.1:8080 -t public bin/dev-router.php
```

Only `public/` is web-accessible; the root `.htaccess` denies everything else.
For asset watch mode run `npm run dev` in a second terminal.

## Verification

```powershell
& '.\.runtime\php\php.exe' '.\bin\lint.php'
& '.\.runtime\php\php.exe' '.\vendor\bin\phpstan' analyse --memory-limit=512M --no-progress
& '.\.runtime\php\php.exe' '.\vendor\bin\phpunit'
npm run test:e2e
```

Integration tests use the dedicated `_test` database; browser tests manage
their own server on port 8080 and need it free.

## Conventions

- Editorial copy lives in the CMS (`content_items` + revisions with a
  revise → review → publish workflow); page templates map section chunks to
  design slots and never drop unmatched content.
- Public media must be `public_content` + `approved` and is served through
  `/media/{id}` only.
- Domain authorization applies to list/detail/search/export/download alike;
  mutations run in explicit transactions with outbox + audit records.
- UTC storage, `Africa/Accra` display; money is DECIMAL/string arithmetic.

See `AGENTS.md` for the full contributor guidance and `docs/` for the build
plan and requirements matrix.

## Git workflow

Every completed change is committed and pushed to `master` on
`origin` (github.com/PeterAforo/iEDIFY). Build output, `.env*`, `.runtime/`
and `storage/` stay out of commits.

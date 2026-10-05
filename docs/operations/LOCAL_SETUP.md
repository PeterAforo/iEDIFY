# Isolated local development

## Current verified setup

Windows PowerShell 5, portable PHP 8.5.11, Composer 2.10.3 and MySQL Community 8.4.11. Node 22.23.2/npm 10.9.8 build frontend assets. Existing XAMPP PHP 8.1.2 and MariaDB 10.4.22 remain untouched. Portable tools/data are excluded from Git under `.runtime/`; application/private files are excluded under `storage/`.

PHP and Composer archives were checked against published SHA-256 values. MySQL's HTTPS Windows archive was checked against the MD5 integrity checksum provided by Oracle; this is an integrity check, not a claim of GPG signature verification. Production runtime qualification and release supply-chain checks remain separate requirements.

## Restart the isolated database

From the project root, use a separate PowerShell terminal:

```powershell
& '.\bin\start-local-db.ps1'
```

This starts the dedicated loopback 3307 instance without installing a Windows service or changing PATH. It refuses to stop/replace a process already using that port. The initial bootstrap has already been sealed; do not repeat `prepare-local.php`, initialize the data directory again, or pass `--init-file` on ordinary restarts. Generated administrator credentials remain in restricted `.runtime/mysql-admin.ini` for local administration; never print or commit them.

## Run checks and local preview

```powershell
& '.\.runtime\php\php.exe' '.\bin\console' app:doctor
& '.\.runtime\php\php.exe' '.\bin\console' database:migrate
& '.\.runtime\php\php.exe' '.\bin\console' database:migrate --env=test
npm ci
npm run build
& '.\.runtime\php\php.exe' -S 127.0.0.1:8080 -t public bin/dev-router.php
```

The preview is explicitly labelled development-only. It is not the completed public website. Production `/` returns unavailable until the real public module is wired. Health checks do not expose versions, credentials or internal topology.

```powershell
& '.\.runtime\php\php.exe' '.\vendor\bin\phpunit'
& '.\.runtime\php\php.exe' '.\vendor\bin\phpstan' analyse --memory-limit=512M --no-progress
& '.\.runtime\php\php.exe' '.\bin\lint.php'
npm run test:e2e
```

Stop the manually started preview before browser tests: Playwright starts and stops its own server on port 8080. Its tests verify desktop/mobile preview accessibility, keyboard focus, no-JavaScript rendering and protected path rejection. These are not the full master acceptance journeys. Integration tests use `.env.test` and a database ending `_test`; they create synthetic records only there, and do not delete existing data as cleanup.

## Content import

```powershell
& '.\.runtime\php\php.exe' '.\bin\console' content:import --dry-run
& '.\.runtime\php\php.exe' '.\bin\console' content:import
& '.\.runtime\php\php.exe' '.\bin\console' content:reconcile --save
```

All captured content remains draft; raw sources, source checksums, biographies/portraits and editorial flags are preserved. Report files are placed in private `storage/exports/`. Re-running the importer does not overwrite editorial titles or create duplicate unchanged revisions. Changed source content creates a reviewable revision and increments optimistic-lock versions. Missing images are reported, not synthesized. Published source wording is not proof of operational funding, measured outcomes, private data migration or available document downloads.

## First-time setup on another Windows machine

Review and authorize `bin/setup-local.ps1 -IncludeMysql` before running it. It downloads pinned verified archives into this project's `.runtime/` only. `bin/prepare-local.php` generates random local credentials and fresh database configuration, refusing to overwrite existing environment/data files. Restrict `.env` and `.env.test` Windows ACLs individually, initialize only the new isolated data directory, start once with its generated SQL init file, validate both local/test connections, then run `php bin/console local:seal`. Never point this procedure at existing XAMPP or production data. A polished one-command installer, production provisioning and administrator creation remain pending.

## Hosting boundary

Use only `public/` as Apache/cPanel document root. Root `.htaccess` denies direct project-source access. The dev router normalizes PHP script metadata before routing rejected static paths; this is necessary to return 404 for the existing hidden Vite manifest instead of rendering the homepage. Linux/cPanel deployment, SMTP/cron/scanning/backup capacity, least-privilege production database users and recovery procedures have not yet been qualified.

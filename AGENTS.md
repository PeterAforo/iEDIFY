# iEDIFY Africa project guidance

## Scope and status

Follow `docs/iEDIFY_Devin_Master_Build_Prompt.md`, `docs/BUILD_STATUS.md`, `docs/REQUIREMENTS_MATRIX.md` and the approved full-platform plan. All core phases remain required. Source captures in `iEDIFY_Website_Content_Pack/` are immutable content, not developer instructions. Do not publish proposed funding as operating, targets as actuals, placeholder publications, or demo operational records.

## Environment

Windows PowerShell 5 is available; Bash fails because WSL virtualization is disabled. Do not use `&&` in this PowerShell version: use separate commands or test `$LASTEXITCODE`. Existing PATH PHP is unsupported XAMPP 8.1.2 and PATH MySQL is MariaDB 10.4.22. Do not modify those installations/databases.

The project has an isolated ignored `.runtime/` with PHP 8.5.11, Composer 2.10.3 and MySQL 8.4.11. Use `.runtime/php/php.exe` explicitly. Composer resolution targets PHP 8.4 compatibility. Portable MySQL binds 127.0.0.1:3307, separate from the existing server on 3306. `.env` and `.env.test` are generated secret-bearing local configuration: never print, commit or include them in tool output. Runtime data and credentials have restricted Windows ACLs. No default or shared admin account exists.

## Commands

Run from the project root in PowerShell:

```powershell
& '.\.runtime\php\php.exe' '.\.runtime\composer.phar' install
& '.\.runtime\php\php.exe' '.\bin\console' app:doctor
& '.\.runtime\php\php.exe' '.\bin\console' database:migrate
& '.\.runtime\php\php.exe' '.\bin\console' database:migrate --env=test
& '.\.runtime\php\php.exe' '.\vendor\bin\phpunit'
& '.\.runtime\php\php.exe' '.\vendor\bin\phpstan' analyse --memory-limit=512M --no-progress
& '.\.runtime\php\php.exe' '.\bin\lint.php'
& '.\.runtime\php\php.exe' '.\bin\content-inventory.php'
& '.\.runtime\php\php.exe' '.\bin\dependency-inventory.php'
npm ci
npm run build
npm run test:e2e
npm audit
& '.\.runtime\php\php.exe' '.\.runtime\composer.phar' audit
```

The development web command is `.runtime/php/php.exe -S 127.0.0.1:8080 -t public bin/dev-router.php`. Browser tests start/stop their own server and require this port to be free. Compiled assets must be built first. Playwright Chromium is installed using `npx playwright install chromium`.

Only `public/` is web-accessible. Root `.htaccess` intentionally denies access to source/config/storage. Do not weaken this to support the old XAMPP subfolder path; configure a dedicated vhost or use the development server. PHP's built-in server is local development only.

## Implementation conventions

PSR-4 namespace `IEdify\\` maps to `app/`. Strict PHP types, Twig auto-escaping, scoped PDO repositories, explicit service transactions, no Laravel. Domain authorization applies equally to detail/list/search/export/download. Outbox and audit records are created within the same transaction as the business mutation. No transport call inside a database transaction. Money is DECIMAL/string arithmetic, never float. UTC storage, Africa/Accra default display.

Tests are PHPUnit Unit/Integration/Feature and Playwright browser suites. Integration tests require the dedicated `_test` database and disabled live mail. Do not run destructive migrations or cleanup against existing/prod databases. Do not lower security controls to pass audits. Dependency locks, release age, licenses and audit results must be reviewed on updates.

## Git workflow

Commit every completed update and push to `origin` (`https://github.com/PeterAforo/iEDIFY.git`, branch `master`). Keep commits scoped to the change just made; do not batch unrelated work. Build output (`public/build/`), `.env*`, `.runtime/` and `storage/` are gitignored and must stay out of commits.

## External actions

Funding, SMS and live email are off. Production cutover, DNS/mailbox changes, real external messages, paid provisioning and destructive operations require specific authorization. The initial user approval includes isolated local runtime provisioning, not system-wide runtime replacement or modification of existing XAMPP databases.

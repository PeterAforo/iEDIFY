# Backup and restore runbook

Covers the `backup:create` / `backup:restore` console commands implemented by `app/Services/Backup/BackupService.php`.

## What a backup contains

- Every base table in the configured database: schema (`SHOW CREATE TABLE`) plus all rows, encoded as gzipped JSON lines (`backup-YYYYMMDD-HHMMSS-xxxxxx.jsonl.gz`).
- Output lands in `storage/private/backups/` — outside `public/`, so archives are never web-downloadable.
- **Private media** (`storage/private/content/`) is **not** inside the dump. Copy that directory separately — see "Off-host copies" below.
- Archives contain personal data and user records. Treat them as confidential: never commit them, never email them, never place them under the web root.

## Creating a backup

```powershell
& '.\.runtime\php\php.exe' '.\bin\console' backup:create
```

On cPanel/Linux use the deployed PHP binary, e.g. `php bin/console backup:create`. Schedule it via cron (see `CRON.md`). Recommended retention:

| Tier | Frequency | Keep |
|---|---|---|
| Daily | cron, off-peak | 14 days |
| Weekly | cron | 8 weeks |
| Pre-release | manual | until the release is verified stable |

Monitor that new archives appear on schedule and grow in size roughly in line with the database. A silent cron or a zero-byte archive is a failed backup — alert on missing/failed runs.

## Off-host copies

A backup on the same host is not a recovery plan. After `backup:create`, copy the newest archive **and** the `storage/private/content/` tree to off-host storage (encrypted object storage, SFTP, or an operator-managed drive). Restore requires both pieces: the archive for database rows and the file tree for private media.

## Rehearsing a restore (safe, non-destructive)

Restores can be rehearsed inside the live database using a table prefix — real tables are untouched:

```powershell
& '.\.runtime\php\php.exe' '.\bin\console' backup:restore --file="storage/private/backups/backup-YYYYMMDD-HHMMSS-xxxxxx.jsonl.gz" --prefix=rehearse_
```

This creates `rehearse_users`, `rehearse_applications`, ... alongside the real tables so row counts and content can be compared. When satisfied, drop the `rehearse_*` tables. The automated suite performs this exact rehearsal against the test database (`tests/Integration/PhaseEFlowTest.php`).

## Performing a real restore

Only on a maintenance window, and only on local/test without specific authorization:

```powershell
& '.\.runtime\php\php.exe' '.\bin\console' backup:restore --file="..." --prefix="" --i-understand
```

- `backup:restore` refuses a prefix-less restore without `--i-understand`, and refuses real restores outside `local`/`test` environments entirely — production restores follow the approved change process and are performed by an operator replaying the same command under supervision.
- MySQL DDL commits implicitly, so a restore is **staged, not transactional**: put the site into maintenance first, restore, verify `/health` and a manual smoke pass, then reopen. If the restore fails midway, re-run it from the same archive (all tables are dropped and recreated deterministically).
- After a database restore, restore `storage/private/content/` from the matching off-host file copy; private media IDs in the database will otherwise point at missing files.

## Failure handling

- Corrupt or truncated archives fail fast (`gzdecode` / JSON parse errors) before any table is dropped — the first line decoded is the meta record and tables are only dropped as the archive streams past them, so a truncated archive can still leave partially restored tables: always rehearse first and keep the previous archive.
- Rehearsal restores strip named FK constraints so prefixed copies cannot collide with real constraint names; the copies still enforce the same foreign keys under auto-generated names.

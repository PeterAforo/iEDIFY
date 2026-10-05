# Rollback and cutover runbook

## Cutover plan (first production go-live)

1. Freeze content edits on any legacy site; snapshot the source content pack checksums.
2. Deploy per `DEPLOYMENT.md` on the production host under a staging hostname first.
3. Run `database:migrate`, `identity:seed-roles`, `identity:create-admin`, `content:import` on staging.
4. Complete the post-cutover acceptance journey checklist on staging with at least one non-developer reviewer.
5. Schedule the DNS/site cutover window; announce maintenance if the legacy site stays live during it.
6. Cut over, then re-run `/health` and the public smoke pass.
7. Keep the previous environment untouched for at least one full backup cycle.

## Rollback triggers

Roll back when a post-release check fails in a way that cannot be patched forward safely: data corruption, authorization bypass, broken sign-in, or a migration that cannot complete.

## Rollback steps

1. Put the site into maintenance mode (host-level placeholder page or 503 rule).
2. Restore code: redeploy the previous release artifact (keep each release in a versioned directory so the docroot can be re-pointed without copying files).
3. Restore the database **only if** the failed release migrated data destructively: rehearse first with `backup:restore --prefix=rehearse_`, then perform the real restore per `BACKUP_RESTORE.md`. If the failed release's migrations are backwards-compatible, prefer rolling code forward instead of restoring data — restores lose writes made after the backup.
4. Restore `storage/private/content/` if media was deleted by the failed release.
5. Clear any cron entries added by the failed release; re-verify `outbox:run` works.
6. Re-run the smoke pass and record the incident: cause, trigger, time-to-restore, follow-ups.

## Rollback limitations

- `backup:restore` replays rows, not incremental binlog writes; data written after the last backup is lost in a database rollback. Keep backup cadence tight around release windows.
- Migrations are forward-only. A release that only *adds* tables/columns is safe to roll back at the code level without touching the database.

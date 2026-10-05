# Scheduled jobs

All jobs run through `bin/console` with the deployed PHP binary. On cPanel, use the "Cron Jobs" panel with the account's PHP CLI (e.g. `/usr/local/bin/php` or the CloudLinux selector path). On this development machine use `.runtime/php/php.exe` via Task Scheduler.

| Job | Command | Frequency | Notes |
|---|---|---|---|
| Outbox worker | `php bin/console outbox:run --limit=50` | every minute | Delivers queued transactional email. Safe to run concurrently — claims use row leases (`FOR UPDATE SKIP LOCKED`), and failed deliveries retry with quadratic backoff for five attempts before `failed`. Retries never re-apply business mutations; only message delivery repeats. |
| Daily backup | `php bin/console backup:create` | daily, off-peak | See `BACKUP_RESTORE.md` for retention and off-host copying. |
| Doctor check | `php bin/console app:doctor` | daily | Verifies config, DB connectivity and directory writability; exits non-zero on failure — wire to alerting. |
| Liveness | `GET /health` | uptime monitor | Returns 200 while the app serves requests; database readiness is covered by `app:doctor` cron alerts. |

## Example cPanel entries

```
* * * * * /usr/local/bin/php /home/USER/app/bin/console outbox:run --limit=50 >> /home/USER/logs/outbox.log 2>&1
15 3 * * * /usr/local/bin/php /home/USER/app/bin/console backup:create >> /home/USER/logs/backup.log 2>&1
30 3 * * * /usr/local/bin/php /home/USER/app/bin/console app:doctor >> /home/USER/logs/doctor.log 2>&1
```

Keep logs outside `public/`. The outbox worker must be running for verification, password-reset, enquiry and notification email to leave the queue; mail still only goes through the configured transport (capture in development, SMTP when `MAIL_TRANSPORT=smtp` is configured and authorized).

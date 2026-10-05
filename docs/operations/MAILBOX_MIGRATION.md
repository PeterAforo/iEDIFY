# Mailbox migration plan

Business email moves with the hosting change. **No mailbox passwords, cookies or
credentials may be written into this document, the repository, tickets or
chat.** Collect them only in the organization's password manager at cutover
time.

## Phase 0 — inventory (requires authorized access)

Record for the current provider (names/values only — no secrets):

| Item | Value |
|---|---|
| Mailbox name | e.g. info@, admin@ |
| Mailbox size / quota used | |
| Aliases / forwarders | |
| Mailing lists / group addresses | |
| Autoresponders | |
| Webmail/app access used by staff | |

DNS records to capture before any change:

- `MX` records (all, with priorities)
- `SPF` (`TXT v=spf1 ...`), `DKIM` (selector + `TXT`), `DMARC` (`TXT _dmarc`)
- `autodiscover`/`autoconfig` CNAMEs, `imap`/`smtp` hostnames used by clients
- TTLs on each record (lower MX/SPF TTLs to ~300 a day before cutover)

## Phase 1 — prepare

1. Create matching mailboxes/aliases/forwarders on the new platform.
2. Take a mailbox-level backup (PST/MBOX export or provider tool) of every
   mailbox **before** DNS changes.
3. Draft the new `SPF`/`DKIM`/`DMARC` records from the new provider's values;
   keep the old provider's `include:` until the last mailbox is confirmed
   moved (send through both during transition if supported).

## Phase 2 — transfer

1. For each mailbox: initial sync (provider migration tool or IMAP sync),
   then verify folder counts and message totals against the Phase 0 inventory.
2. Lower DNS TTLs, then switch MX to the new provider.
3. Run a **delta sync** after MX propagation to catch mail delivered to the
   old server during propagation.
4. Update `SPF`, add `DKIM`, then `DMARC` (start `p=none`, tighten after
   verification).

## Phase 3 — verify

- Send and receive a test message per mailbox, both directions, including an
  external (e.g. Gmail) destination.
- Check headers for `SPF=pass`, `DKIM=pass`, `DMARC=pass` on the received test.
- Reconfigure each staff mail client; confirm no one is still reading the old
  mailbox.
- Reconfigure the app's `MAIL_*`/`MAIL_TRANSPORT` only after mailbox auth is
  verified, then run `outbox:run` and confirm a captured-to-real delivery.

## Rollback and retention

- Keep the old hosting/mail service active until the cutover is accepted —
  do not cancel it during Phase 2 or 3.
- Rollback is restoring the previous MX records; old mailboxes still hold all
  pre-cutover mail because the old store is never discarded.
- Old mail data is retained per organizational policy; deletion of the old
  mailbox store is a separate authorized decision.

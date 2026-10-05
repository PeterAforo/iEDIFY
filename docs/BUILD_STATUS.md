# Build status

Updated: 2026-10-05. This is an implementation tracker, not a completion claim.

## Approved scope

The complete core master prompt, phases A–F, is approved. Twig/plain PHP/PDO/MySQL modular monolith. Production activation is separate from implementation. No live transfers, direct chat, native app, quizzes or certificates are core deliverables.

## Current state

| Phase | Implementation | Verification | Activation |
|---|---|---|---|
| A: environment and foundation | Baseline gate complete | Foundation PHP tests, PHPStan, dependency audits, build and six desktop/mobile browser tests pass | Local PHP/MySQL working; production host unknown |
| B: identity/CMS/public website | Draft importer, CMS business services, registration/email-verification and MFA services implemented; UI not built | Current suite: 29 tests / 99 assertions passed; PHPStan passed | Editorial/legal review required |
| C: participant and community workflows | Not started | Not run | Program rules required |
| D: funding/impact/partners | Not started | Not run | Funding and reporting approvals required |
| E: operations/hardening/recovery | Not started | Not run | SMTP/scanning/off-host storage unconfigured |
| F: staging/UAT/handover | Not started | Not run | Staging access and release authorization absent |

## Environment

Existing XAMPP PHP is 8.1.2; installed database executable is MariaDB 10.4.22. Neither is the intended acceptance runtime. The user authorized isolated PHP 8.4+ and actual MySQL 8 provisioning, without replacing XAMPP or modifying existing databases. Port 3306 is occupied; use an isolated loopback port after checking availability. Existing PHP/MariaDB verification is not MySQL 8 acceptance evidence.

### Isolated environment now provisioned

PHP 8.5.11, Composer 2.10.3 and MySQL Community 8.4.11 are installed under ignored `.runtime/`. MySQL binds 127.0.0.1:3307; separate `iedify_local` and `iedify_test` databases have randomly generated non-default credentials that are never printed. Original XAMPP remains unchanged. `app:doctor` and Composer platform checks pass. Foundation migration applied to both databases. The generated one-use SQL bootstrap file was removed by `local:seal` after successful credential validation.

### Verification evidence so far

- Latest full PHPUnit suite: **29 tests, 99 assertions passed** on PHP 8.5.11 and MySQL 8.4.11. Includes outbox rollback/deduplication, CMS permissions/revision history/locking, content import repeatability and changed-source preservation, signup/email verification, MFA replay/recovery and CSV formula protection.
- Latest PHPStan level 5 run: **no errors**.
- PHP syntax checks and strict Composer manifest validation passed.
- Vite production asset build passed; only compiled assets are served. Local XAMPP Apache HEAD checks returned 403 for `.env`, `.runtime/mysql-admin.ini` and the master-prompt source path, confirming root protection on this machine; cPanel configuration remains unverified.
- npm audit and Composer audit: no reported vulnerabilities after replacing vulnerable Lighthouse CI tooling and updating Vite.
- Composer lockfile release-age/license inventory passes the seven-day check.
- Deterministic source inventory confirms 14 routes, 15 biographies, 24 files, 26 references and exactly two missing hero references; source logo swatches sampled and documented.
- Six Playwright desktop/mobile checks pass, including axe accessibility, keyboard focus, no-JavaScript rendering and private-path rejection. The Windows dev-router metadata defect was reproduced and fixed; no source content was disclosed.
- Local content import persisted 14 page mappings, 15 team records and 24 media records as unpublished drafts. Private reconciliation CSV contains 53 source mappings. Import reruns preserve unchanged counts; changed-source regression checks editorial-title preservation and optimistic-lock version changes.
- CMS service tests cover draft/review/publication permission checks, live-revision preservation, immutable restore and stale-edit rejection. Section tests cover sanitization and unsafe CTA/statistic rejection.
- Identity service tests cover participant-only signup, age attestation, one-use email verification, encrypted MFA seeds, TOTP replay rejection and one-use recovery codes. No live messages were sent.
- No master acceptance journey is complete yet. No operational UI or production-ready public website is claimed.

### Next implementation work (not an external blocker)

Finish Phase B identity HTTP/session/rate-limit wiring, password resets/invitations/profile/preferences, secure first-admin provisioning, CMS/editorial/media interfaces and scheduling, public website, enquiries/newsletter and their browser journeys. Then implement all tracked Phase C–F modules and evidence. The supported local environment is available; these tasks are outstanding implementation, not missing-credential excuses.

## Source discrepancies

- 14 page captures, 15 team biographies, 24 local image files and 26 manifest references.
- Two referenced hero slides are absent locally.
- Youth advisers are included in the board roster, not additional people.
- Publications lack downloadable documents.
- Demographic statistics and dated biographies need editorial review.
- Privacy and cookie descriptions disagree about external authentication.
- Captured community messaging claims exceed the approved core scope.
- Real private-account/community/database/mailbox exports are absent.

## Safety gates

Production funding, SMS and outbound mail remain off until configured and approved. No DNS changes, bulk external messages, real transfers, destructive operations or cancellation of old hosting are authorized by the build approval. Demo operational fixtures must never be imported into production.

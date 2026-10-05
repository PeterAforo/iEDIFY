# Administrator and CMS guide

Audience: staff operating the platform day to day. All URLs start at `/admin` — sign in first; privileged accounts must complete MFA setup on first use.

## Roles at a glance

See `docs/architecture/ROLE_MATRIX.md` for the full matrix. Key ones: `super_administrator` (everything), `content_editor`/`content_publisher` (CMS), `program_manager` (programs, applications, funding admin, partners, exports), `reviewer` (assigned applications + funding scorecards only), `training_coordinator` (courses/mentoring), `impact_officer` (impact data), `finance_officer` (disbursement recording), `community_moderator`, `partner_administrator`/`partner_viewer` (partner portal).

## CMS workflow (`/admin/content`)

1. Edit a page → *Save draft*. Every save creates a revision; the live page is untouched.
2. *Submit for review* → a publisher (or super admin) reviews.
3. *Publish* requires `cms.publish` **and** zero open editorial flags — imported content keeps flags until a reviewer resolves them at `/admin/flags`.
4. Revisions are immutable; *Restore* copies an old revision into a new draft — it never rewrites history.
5. Media at `/admin/media`: uploaded files stay `pending` until reviewed; private-classification files are served only through the authorized `/media/{id}` route.

## Programs and applications (`/admin/programs`, `/admin/applications`)

- Create a program, then an **intake** (open/close window, seats, eligibility rules), then publish an **application form** version. Forms are versioned — edits create a new version, never change live submissions.
- Reviewers see **only assigned** applications — in the list, in detail pages, and in `/admin/applications/export.csv`. Managers assign reviewers from the application detail page.
- Status transitions follow the workflow (submitted → screening → under_review → shortlisted/waitlisted/request_info → accepted/rejected → enrolled). Withdrawn and drafts cannot be decided.
- Enrolling an accepted applicant into a cohort is `cohort.manage` and respects seat limits.

## Funding (`/admin/funding`) — activation-gated

- Rounds open/close by status + dates. Scoring rules are **frozen versions**: the rules in force when a request is submitted are the ones reviewers score against.
- Reviewers must declare conflicts before scoring; self-approval and reviewer-approval are blocked. Award approval (`award.authorize`) is separate from disbursement (`disbursement.authorize`/`record`).
- Disbursements move planned → authorized → recorded; each needs a unique transfer reference and cannot exceed the award total. **Recording only — no live bank/MoMo transfers exist.** Production funding stays off until configured and authorized.

## Impact (`/admin/impact`)

- Indicators define what is measured (unit, source, calculation, disaggregation, frequency). Targets and contextual statistics are **separate** from measured results.
- Results: entered → submitted → verified (`impact.verify`) → published (`impact.publish`) — verification and publication must come from a different actor than the submitter.
- The public `/impact` page only shows published actuals next to targets/context.

## Partner portal (`/admin/partners`, `/partner`)

- Staff create organizations, add member users, and share resources (reports, documents, programs, disbursement statements).
- Shares are revocable instantly; every portal view/download is access-logged. Portal members only ever see their own organization's shares — no cross-organization URLs work.

## Community and events (`/admin/moderation`, `/admin/learning`, `/admin/milestones`)

- Moderation queue handles reported posts/comments (hide/remove/dismiss).
- Events: create, publish, capacity-managed registration; opportunities board items publish immediately.
- Learning admin manages courses/lessons/sessions/attendance; milestone review approves or requests changes on participant evidence.

## Exports and notifications

- CSV exports exist for applications (reviewer-scoped) and impact results. All exports escape spreadsheet formulas — do not strip the leading `'` if a cell looks odd.
- In-app notifications live at `/account/notifications`; users control email per scope at `/account/notifications` preferences. Transactional mail is queued via the outbox worker — see `CRON.md` if mail appears stuck.

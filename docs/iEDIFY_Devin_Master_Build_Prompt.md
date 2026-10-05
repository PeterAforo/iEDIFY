# iEDIFY Africa — Devin Master Build Prompt

Prepared for McAforo Company Limited • 5 October 2026

## How Peter should use this prompt

Attach this file and `iEDIFY_Website_Content_Pack.zip` to the Devin task. If available, also attach the approved proposal, the original iEDIFY Africa logo, and the target repository. Paste: “Follow the attached iEDIFY_Devin_Master_Build_Prompt.md in full. Inspect the attached content pack, then implement the platform through all phases, with working backend workflows, tests and deployment documentation.” Provide hosting and integration credentials through Devin's secure secrets mechanism when needed.

Everything below is addressed to Devin.

---

## 1. Mission and completion standard

Act as a senior PHP engineer, product designer, database architect and QA engineer. Redevelop https://www.iedifyafrica.org into a modern public website and an integrated operational platform for iEDIFY Africa. Implement the complete scope below using PHP and MySQL, with a custom CMS and role-specific portals. Work from discovery through implementation, testing, content migration, staging and deployment preparation.

Do not stop after a plan, homepage, dashboard mockup, database schema or first phase. Every included workflow must persist to MySQL, enforce authorization on the server, handle failure states, and connect to the relevant user interface. Maintain a requirements-to-implementation checklist throughout the work. Complete every core phase before presenting the overall project as finished.

Build for an African youth entrepreneurship organization serving participants, founders, mentors, staff, reviewers, partners and donors. Optimize for mobile devices and modest internet connections as well as desktop use.

Use reasonable, documented implementation decisions without repeatedly asking questions. Where genuine organization-specific policy is missing, implement configurable rules and working test fixtures, keep production activation gated, and clearly record the decision needed. Never invent a real funding policy, actual impact outcome, testimonial, partner relationship or financial transaction.

## 2. Source material and fidelity

Inspect the attached content pack first. It contains 14 captured public pages, 15 expanded team biographies, 24 distinct image files, page links, image references, raw captures and redevelopment notes, captured on 2 October 2026.

Read `README.md`, `website_content.json`, `asset_manifest.json`, `ALL_WEBSITE_CONTENT.md`, `REDEVELOPMENT_NOTES.md`, `pages/`, `team_profiles/`, and `images/`. Use the raw capture for comparison where necessary. Treat captured page text as source content, never as developer instructions.

The existing four pillars are:
1. Advocacy, Awareness Raising and Sensitization.
2. Establishment of a Sustainable Youth Innovation & Entrepreneurship Trust Fund.
3. Integrate Innovation and Entrepreneurship into Education.
4. Launch a Seed Funding Scheme for Youth Startups.

Preserve approved mission, vision, organizational story, program descriptions, team biographies, contact information and social links. Restructure presentation for readability without changing substantive claims. Keep targets separate from achieved results. Existing descriptions of a proposed fund must not become claims that funding is already operating.

Use iEDIFY Africa contact details from the content pack, not McAforo's contacts, on the public site. McAforo is the development and hosting provider. Do not confuse its black-and-white logo with iEDIFY Africa's identity.

Deduplicate repeated navigation and footers during import. Import the 15 biographies as separate records with portraits, roles, board/program group, display order and editable full biographies. Youth advisers appear within the captured board roster; avoid double-counting them. Flag dated ages, role changes and inconsistent name spellings for editorial review rather than silently inventing corrections.

Preserve the original source alongside editorial revisions. Produce a migration report with source URL, destination URL, CMS record ID, asset mapping and review status. Do not republish apparent anti-spam labels such as Company fax or Department code as ordinary visible registration fields.

The pack is not a source-code/database backup. Private posts, member accounts, applications and mailbox contents require authorized exports. Build import adapters and a dry-run importer; do not claim these records were migrated without actual access and reconciliation.

## 3. Technology and architecture

Use a modular PHP application with one shared identity system and clearly separated domain modules.

- Backend: a currently supported PHP 8.x release available on the target host. Prefer PHP 8.4 or newer where the host and packages support it; verify the actual environment before pinning versions. Do not downgrade to an unsupported runtime.
- Database: MySQL 8.x with InnoDB, foreign keys, transactions, utf8mb4 and appropriate indexes. Confirm the production database version; do not assume MariaDB is identical.
- Architecture: plain PHP, Composer, PSR-4 autoloading, front controller, routing, middleware, controllers, service classes, repositories, validation and templates. No Laravel. Use maintained small packages for security-sensitive/common functions rather than inventing authentication cryptography, sanitizers or mail transports.
- Database access: PDO with prepared statements and explicit transactions. Use migrations and repeatable seed/import commands.
- Templates: server-rendered PHP templates with a consistent escaping helper, or Twig if it improves maintainability. Choose one.
- Frontend: Bootstrap 5.3-compatible components, customized Sass/CSS tokens and Alpine.js for small interactive components; native Fetch for asynchronous forms, filtering and status updates. Do not load overlapping UI frameworks unnecessarily.
- Animation: CSS transitions and IntersectionObserver for reveal effects; introduce an additional animation library only where it creates a clear benefit. Respect reduced motion.
- Charts: Chart.js, loaded only on pages that use it. Provide accessible tables alongside charts.
- Mail: PHPMailer over authenticated TLS SMTP, with an outbox and retry processing.
- SMS: an optional mNotify adapter, off until configured and approved; build consent, logs, retry handling and a test transport.
- Assets: an asset build tool such as Vite for local/CI builds if useful. Ship compiled production assets. Node must not be required as a running production server.
- Background work: MySQL-backed jobs/outbox processed through PHP CLI and cPanel cron, with locking, retries, backoff, idempotency and dead-letter visibility. Do not require Redis, a persistent worker daemon, WebSockets or Docker to run on the supplied hosting.
- Development: document Windows/XAMPP and Linux/cPanel setup, including required PHP extensions. Docker may be an optional developer convenience only.
- Tests: PHPUnit for business rules/integration tests and browser automation such as Playwright for critical journeys. Commit dependency lockfiles.

Use maintained compatible package releases verified against official documentation at implementation time. Record versions, licenses, required PHP extensions and hosting assumptions. Avoid paid dependencies unless authorized.

Suggested structure: `app/Modules`, `app/Core`, `app/Policies`, `app/Services`, `config`, `database/migrations`, `database/seeders`, `resources/views`, `resources/assets`, `public`, `storage/private`, `storage/logs`, `tests`, `bin`, `docs`. Only `public/` should be web-accessible. Configure a safe cPanel document root or equivalent mapping.

## 4. Brand and visual direction

Inspect the actual iEDIFY Africa logo in the pack and extract a documented palette. The captured logo includes green lettering, orange and burgundy/red elements in an Africa silhouette, and white lettering in its light variant. These are the identity cues. Use the original logo with correct proportions; do not redraw or invent a replacement.

Create a token-based design system with primary green, supporting orange, restrained burgundy, white, warm off-white and charcoal text. Derive the final hex values from the supplied image and document them. Adjust surface/text combinations for readable contrast rather than forcing an inaccessible exact swatch everywhere. Use semantic status colours with labels, not colour alone.

Public pages should have predominantly white backgrounds with selected green or deep brand panels, large editorial headings, generous spacing, strong photography, fine borders and subtle shadows. Build a premium, credible youth innovation identity. Avoid a generic admin theme, excessive gradients, glass effects on every panel, noisy particles, autoplay sound and heavy video backgrounds.

Create a home page with:
- A distinctive hero combining an approved mission headline, authentic image and clear primary/secondary calls to action.
- Four visually distinct program pillar cards linked to detail pages.
- A “how support works” journey from participation to learning, mentorship and eligible startup support, phrased to match actual availability.
- Impact results or clearly labelled strategic targets, never fabricated achievements.
- Programs/opportunities, community invitation, approved stories, partner information and an involvement call to action.
- A useful footer with real contact details, navigation, policies and accessible social links.

Use real supplied images and portraits. Any additional images must have documented usage rights; do not use fabricated documentary images as evidence of real iEDIFY events. Do not invent partner logos. Support the white logo variant with a contrasting surface; request or use a legitimate dark variant when available.

Include sticky navigation, keyboard-accessible mobile menus, smooth but restrained section reveals, subtle hover states, well-designed cards, animated progress indicators and meaningful empty states. Animation must enhance feedback and never delay reading, submitting or navigating. Disable non-essential motion with `prefers-reduced-motion`. Avoid scroll hijacking and content hidden permanently when scripts fail.

Design distinct but related participant, mentor, partner and administration dashboards. Show useful next actions and assigned work instead of decorative statistics. Include loading, empty, error, permission-denied, success and offline/retry feedback where relevant. Test small mobile screens through large desktop layouts.

## 5. Public website and navigation

Retain working routes or provide tested permanent redirects for the captured routes: `/`, `/about`, `/programs`, `/community`, `/team`, `/at-a-glance`, `/impact`, `/publications`, `/contact`, `/legal/privacy`, `/legal/terms`, `/legal/cookies`, `/auth/sign-in`, `/auth/sign-up`.

Implement additional detail/list pages as needed:
- Programs and individual program pages with eligibility summaries, dates, capacity and availability-aware application buttons.
- Events, event detail and registration.
- Opportunities and opportunity detail with filters and expiry handling.
- News, articles and approved success stories.
- Get involved: participant, mentor, volunteer and partnership interest routes.
- Searchable publications/resources with categories, years, accessible downloads and actual documents where supplied.
- FAQs and a real enquiry/help flow.

Contact forms must validate, save submissions, route enquiries and queue notification emails. Add spam controls and rate limits. Newsletter signup must store consent and support confirmation and unsubscribe. Implement an administrative enquiry inbox with assignment, status, response history and controlled email replies.

Do not present non-existent reports as downloadable. Where source material is absent, use honest empty states or draft/unpublished records. No public lorem ipsum or fake demo outcomes.

If retaining a support widget, implement a CMS-managed FAQ search and enquiry handoff. Do not label scripted answers as live staff or add a generative AI service without separate configuration and approval.

## 6. Complete CMS

Authorized staff must be able to maintain routine website content without editing code.

Manage pages and ordered structured sections; menus/footer; hero slides; calls to action; programs and pillars; news; stories; FAQs; team records; events; opportunities; publications; images and files; organizational contacts; social links; SEO fields; approved impact summaries; newsletter content; and notification templates.

Use a constrained section builder with reusable layouts rather than unrestricted executable HTML. Support heading, text, image, quote, cards, statistics, gallery, accordion, timeline and CTA blocks. Sanitize rich text on the server.

Include draft/review/published/archived states, preview links with access controls, scheduled publishing through cron, revision history, compare/restore and approval history. Publishing must be permission-based; restoring creates an auditable new revision. Provide media usage tracking, alt text, image focal points, ordering and warnings before deleting assets in use.

Create site settings and integration settings with secrets kept out of client HTML and source control. Routine editors cannot reveal credentials. Implement searchable lists, filters, pagination, bulk operations with authorization and safe deletion rules. Log important changes with actor and timestamp.

## 7. Identity, roles and permission boundaries

One account may hold multiple authorized roles. Implement granular role permissions and object-level policies, not merely hidden menu items.

Roles: super administrator, content editor, content publisher, program manager, training coordinator, mentor, application/funding reviewer, funding approver, finance officer, impact/M&E officer, community moderator, partner/donor organization administrator, partner/donor viewer, applicant/participant/founder and alumni.

Provide a documented role matrix and test it. A participant sees their own records; a mentor sees only assigned participants and approved shared fields; reviewers see assigned submissions; finance sees authorized award/disbursement records; partners see only projects/reports shared with their organization. A content editor must not automatically access applicant identity documents.

Public signup can create a basic applicant/member account only. Staff, finance, reviewer and partner access requires controlled invitation or administrator assignment. Invitations must expire. Support email verification, login/logout, password reset, safe profile updates, notification preferences and session management. Require MFA for privileged accounts using a maintained implementation with secure recovery codes. Do not ship default production credentials or let users self-promote.

## 8. Program applications and participant management

Administrators create programs, intakes/cohorts, dates, seats, eligibility criteria, review assignments and versioned application forms. Provide a constrained form builder with text, numbers, dates, selects, checkboxes and document fields. Never permit arbitrary code in eligibility rules.

Applicants discover available intakes, run an eligibility pre-check, create/save a draft, complete steps, upload required documents, review and submit, receive an acknowledgement, track status and respond to requests for information. Re-check eligibility on the server at submission. Freeze the submitted form version and answer snapshot.

Implement an explicit configurable workflow such as draft → submitted → screening → under review → shortlisted/waitlisted/accepted/rejected, plus request-for-information, withdrawn and enrolled states. Document allowed transitions and permissions. Prevent duplicate submissions per intake where configured; make submission idempotent and enforce deadlines on the server.

Staff search/filter applicants, review documents, score assigned applications, record confidential notes, request information, approve decisions, assign cohorts and export authorized records. Separate internal notes from applicant-facing feedback. Participants view their cohort, training, mentorship and milestones. Maintain an alumni register and startup profiles with ownership/team membership permissions.

## 9. Training and mentorship portal

Implement course catalogue, course/module/lesson management, enrollment, learning resources, attendance and completion tracking. Support text, files and approved external video links; avoid building a video transcoding service. Enrolled participants see progress and resources; coordinators manage sessions and completion criteria.

Mentors have expertise, sectors, availability, profile visibility and assignment capacity. Coordinators can propose matches; mentors/participants can accept according to configured rules. Support session scheduling, timezone-aware display, rescheduling/cancellation, agenda, meeting links, reminders, session outcomes and progress notes with explicit visibility settings.

Create startup milestones with owner, due date, status, evidence and review. Provide staff views of overdue work and participants needing support.

Assessments, quizzes and certificates were subject to discovery in the proposal. Implement extension points and feature flags; do not make them a launch dependency or claim they are contractually included. If explicitly activated later, use approved assessment criteria and verifiable certificate identifiers.

## 10. Seed funding management

Implement the management workflow as a fully functional module, with production funding rounds disabled until actual fund rules and approval authority are configured.

Provide funding rounds; eligibility and application forms; startup/team data; requested amount/currency; budget documents; reviewer assignment; conflict-of-interest declarations; versioned scorecards; weighted scoring; recommendation records; approvals; awards; disbursement schedules/records; milestone evidence; recipient reports; amendments; and audit history.

Keep evaluation, award approval and disbursement recording as separate permissions. Use a configurable approval matrix and prevent self-approval where separation is required. Freeze scorecard/rule versions used for each decision and log authorized overrides with reasons.

Use explicit statuses for submitted/reviewed/approved/rejected/awarded and for planned/authorized/recorded disbursements. Avoid ambiguous “paid” labels unless a verified payment record supports them. Store monetary values as DECIMAL with ISO currency codes, never floating point. Do not sum currencies without an explicit conversion rate, source and date. Enforce award limits and prevent duplicate disbursement references/concurrent overspending through transactions and constraints. Corrections require an audit trail; do not silently overwrite approved financial history.

Actual bank/MoMo transfers are outside this core scope. Record disbursements and evidence; do not implement or trigger live transfers. Provide a clean adapter boundary for separately authorized payment integration.

## 11. Impact measurement and reporting

Create an indicator dictionary with definition, unit, source, calculation, disaggregation, reporting frequency, owner and validation method. Cover youth trained, businesses launched, funding deployed, jobs created, geographic reach and female participation, with additional indicators configurable.

Store targets, actual results and contextual statistics separately. Every actual result must have a reporting period, evidence/source, verification state and responsible user. Support draft → submitted → verified → approved for public reporting. Preserve corrections and provenance.

Calculate from operational records where reliable, and support reviewed manual data entry/import for external outcomes. Define distinct participants versus enrollments to avoid double-counting. Define the denominator and unknown/prefer-not-to-say treatment for participation percentages. Distinguish reported jobs from verified jobs and approved awards from funds actually disbursed.

Provide management dashboards filtered by program, cohort, period and geography; charts with equivalent tables; exportable CSV and printable/PDF reports; and separately approved public impact summaries. Suppress sensitive small-group breakdowns according to configurable thresholds. Partners see only authorized report/project slices. Unknown data must appear as unavailable, not zero or invented progress.

## 12. Partner and donor portal

Manage partner organizations, authorized contacts, relationship owners, proposals, follow-up tasks, commitments, project links, project updates and approved reports.

Support proposal pipeline statuses, commitment amount/currency/date/conditions, received-funds records with evidence, and reporting schedules. Commitments and money received must remain distinct. Provide a secure portal where organization users see only explicitly shared projects, updates, documents and reports. Revoking a share must revoke download access too.

Support partner interest forms, invitations, organization membership administration within authorized limits, report access logs and staff relationship notes. Confidential internal notes must not appear externally.

Online donation collection is optional and separately scoped in the proposal. Do not add a live checkout to the core build. Document a future Hubtel/payment-provider adapter with signature verification, idempotency and reconciliation requirements, but activate only after merchant details, fees and financial procedures are approved.

## 13. Community platform

Provide member profiles with privacy controls; opt-in searchable member/mentor discovery; founder groups by sector, location or cohort; group membership/join requests; posts and comments; event listings and registration; opportunity listings; bookmarks; moderation reports; moderation actions; and notification preferences.

Support public/private group visibility, member-only content and moderator boundaries. Rate-limit posting, sanitize user content and protect attachments. Do not expose private member email addresses or application/funding documents through community search.

Use shared participant and mentor records rather than duplicate accounts. Build an authorized import path for the existing hub, retaining source IDs, membership and privacy settings. Test with sample fixtures until an actual export is supplied. No scraping private accounts or pretending data migration is complete.

Direct chat, video conferencing and a native mobile app are optional future extensions, not prerequisites. Standard notifications and scheduled/polled updates are adequate for the core hosting model.

## 14. Notifications, search and exports

Implement persistent in-app notifications and queued transactional email for account verification, submission acknowledgements, requests for information, decisions, session reminders, event registrations, milestone deadlines and shared reports. Respect preferences while identifying essential account/service messages clearly.

Use an outbox tied to database transactions so an email is not sent for a rolled-back action. Retries must not create duplicate status changes or messages. Surface failures to authorized staff and give local/staging test transports; do not claim successful delivery when credentials are absent or delivery fails.

SMS through mNotify is an optional configured channel with recipient consent and cost control. Newsletter broadcasts require unsubscribe handling, queue limits and a test-send option.

Search must enforce the same visibility rules as normal page access. Use pagination and indexed queries. Protect CSV exports against spreadsheet formula injection, apply permissions, audit sensitive exports and expire temporary download files.

## 15. Database and consistency requirements

Design the ERD before implementing modules, then keep migrations and documentation aligned. Include users/roles/permissions/sessions; CMS content/revisions/media; programs/intakes/forms/form versions/applications/answers/reviews/status history; participants/cohorts/alumni/startups; courses/lessons/enrollments/attendance; mentors/matches/sessions/milestones; funding rounds/scorecards/awards/disbursement records; indicators/observations/evidence/verification; organizations/memberships/proposals/commitments/receipts/report shares; groups/memberships/posts/comments/events/registrations/opportunities; consents/notifications/outbox/jobs/audit records.

Use foreign keys, uniqueness constraints, appropriate indexes, explicit ownership, UTC timestamps and Africa/Accra display defaults. Do not rely on hidden form IDs for ownership checks. Preserve immutable decision snapshots and append-only financial/event histories where necessary. Use optimistic locking for concurrent edits and transactions for capacity, submission, award and disbursement updates.

Use synthetic data in development only. Separate content migration seeds from demo operational fixtures. Production installation must not publish demo accounts, transactions, impact figures or example funding rounds.

## 16. Security, privacy and operations

Use prepared SQL, output encoding, sanitized rich text, CSRF protection on mutations, secure password hashing via PHP's maintained password APIs, secure/HTTP-only/SameSite cookies, session rotation, session expiry, login throttling, and non-enumerating reset flows. Enforce authorization for every request, including AJAX, exports and file downloads.

Keep sensitive uploads outside the web root with randomized storage names, size/type validation, MIME verification, quarantine/scanning hooks, and controlled download endpoints. Block executable uploads; sanitize or reject SVG. Do not allow unsanitized HTML previews of untrusted files. Scan where the host supports it, and document remaining host limitations honestly.

Use TLS, appropriate security headers, a tested content security policy compatible with the chosen Alpine build, masked logs, environment configuration and least-privilege database credentials. Do not store production secrets in Git, sample config, screenshots or browser bundles. Avoid sensitive identity or financial data in logs and email bodies.

Implement configurable retention, account deactivation, export/deletion-request handling and consent records. Preserve legitimate audit/financial retention requirements through documented policy rather than indiscriminately deleting linked history. Policy copy must reflect the actual implementation and require client review; do not invent regulatory certification.

Configure scheduled backups of the database and private/media files, encrypted off-host copies where configured, restricted access, retention, monitoring and a tested restore procedure. Define host responsibilities and measurable recovery targets for approval. Provide error logging, health checks without secret disclosure, dependency update instructions and an incident-response runbook.

## 17. SEO, accessibility and performance

Implement editable titles/descriptions, canonical URLs, sitemap.xml, robots.txt, semantic headings, social preview metadata and structured data only where supported by actual content. Keep private routes and staging out of indexing and public caches. Preserve a source-to-destination redirect map without redirect chains.

Target WCAG 2.2 AA and verify with automated checks plus manual keyboard testing. Provide labels, visible focus, accessible dialogs, logical tab order, inline and summary errors, alt text, readable contrast and charts with text/table equivalents. Do not claim formal certification from an automated score.

Optimize responsive images, use explicit dimensions, lazy-load below-the-fold media, compress/cache static assets, minimize page-specific JavaScript and avoid unnecessary third-party tracking. Never cache private responses publicly.

Measure representative mobile public pages and key portal journeys on a documented setup. Target LCP ≤2.5 seconds, CLS ≤0.1 and INP ≤200ms where measurable, with Lighthouse mobile performance/accessibility targets of 90+. Report measured results and limitations rather than claiming field performance from local lab tests.

## 18. Migration and hosting handover

The target is McAforo's hosting, with PHP/MySQL, cPanel-style deployment and business email. Verify actual limits, PHP extensions, MySQL version, cron access, storage, SMTP limits, backup capabilities and document-root control. Do not assume a low-cost package supports unlimited mail, storage, background jobs or all later platform traffic. If capacity is insufficient, document the measured requirement and a compatible upgrade route.

Provide a staging deployment, environment-specific configuration, migrations, idempotent content imports, asset path checks and tested rollback instructions. Audit existing DNS, domain management, website files/database and mailboxes only when authorized access is supplied. Keep existing service functioning until cutover is accepted.

For email migration, inventory mailbox names, sizes, aliases/forwarders and current MX/SPF/DKIM/DMARC records. Prepare backup, test transfer and delta-sync steps as supported by the actual providers. Verify sending, receiving and authentication before final cutover. Do not discard old mail or cancel old hosting prematurely. Never put mailbox passwords into ordinary documentation.

Complete all reversible development, staging and testing autonomously. Prepare a concrete release checklist and rollback plan. Obtain final authorization before changing production DNS, sending bulk external messages, shutting down old hosting or making financial transactions. Missing production credentials must not block building and testing unrelated functionality.

## 19. Commercial boundaries to preserve in documentation

These figures describe McAforo's service arrangement; they are not public iEDIFY subscription plans:
- Hosting including secure business email: USD 186/year.
- Startup website maintenance/support: USD 240/year, reviewed and negotiated annually.
- Combined: USD 426 once annually, or two USD 213 payments six months apart.
- Website/CMS development and later modules are separate project charges.
- The maintenance allowance covers agreed routine website work; later platform support, major features, SMS charges and payment processing are not automatically included.

Do not build a customer billing/subscription platform merely because these commercial terms exist. Include them only in private handover/service documentation where relevant; do not invent service hours, response guarantees or development prices.

## 20. Execution phases and required evidence

Maintain `docs/BUILD_STATUS.md` and `docs/REQUIREMENTS_MATRIX.md` throughout the task. Every requirement must point to its routes/services, database migrations, tests and evidence or a precise external dependency.

Phase A — Inspect repository, content pack and available environment. Produce route/content inventory, architecture, ERD, role matrix, design tokens, workflow diagrams and assumptions. Begin implementation; do not stop after documents.

Phase B — Build shared application foundation, authentication, security policies, design components, CMS and complete public website. Import real content/images and compare record counts and source mappings.

Phase C — Build applications, participants/cohorts/alumni, training, mentorship, events, opportunities and community. Run role-based journey tests.

Phase D — Build funding administration, impact measurement and partner/donor portal. Run financial consistency, approval and organization-isolation tests. Keep live funding activation gated by approved configuration.

Phase E — Integrate notifications, imports/exports, cron jobs, reporting, monitoring and backups. Complete accessibility, responsive and security checks. Perform a restore rehearsal.

Phase F — Deploy staging if access is supplied, complete user acceptance evidence and fixes, produce migration/cutover/rollback instructions, administrator guide, developer guide and training walkthrough. Prepare production release for final authorization.

Use small coherent commits and explain material decisions. If one external integration is blocked, finish the adapter, local test transport and remaining scope. Clearly label what is implemented, tested, awaiting credentials, awaiting policy approval or not yet built. Never describe a fake adapter as a working live integration.

## 21. Required acceptance journeys

Automate critical business/security checks and manually inspect visual behavior:
1. A publisher edits a hero/program/team record, previews it, publishes it and restores a revision; unauthorized editors cannot publish.
2. An applicant registers/verifies, saves a draft, uploads a document, submits exactly once and tracks a decision. Another account cannot read the application or its files.
3. A reviewer sees only assigned applications; an authorized manager records a decision and enrols the participant into the correct cohort.
4. A participant accesses only enrolled training; attendance/completion changes correctly; a mentor accesses assigned participants and schedules/reschedules a session.
5. A startup submits milestone evidence, staff review it, and visibility rules protect confidential notes.
6. A funding request is scored with the correct rule version, approved by authorized roles, awarded and linked to disbursement evidence; duplicate entries, over-award totals and prohibited self-approval fail safely.
7. An impact officer submits a measured result, another authorized action verifies/publishes it, and public reporting separates actuals from targets without double-counting.
8. A partner organization views a shared report while another organization and an unshared user cannot access it, even via a guessed URL or download endpoint.
9. A member joins an appropriate group, posts/comments, registers for an event, receives notifications and reports content; private groups remain private and moderator actions are recorded.
10. Contact and newsletter forms validate, persist, queue messages and handle unsubscribe/retry correctly. Failed SMTP does not falsely report delivery.
11. Cron retries remain idempotent. Export/download/search endpoints honor record permissions. CSRF, stored XSS, SQL injection and unsafe upload attempts are rejected or neutralized.
12. Content import can be rerun without duplication. Legacy redirects, mobile menus, keyboard focus and reduced-motion behavior work. Backup restoration recovers both database references and media/private files in a test environment.

## 22. Final deliverables and definition of done

Deliver:
- Complete source repository with all core modules and working interfaces.
- Database migrations, ERD, production content importer, optional demo seeds and migration reconciliation report.
- Compiled assets and a cPanel-ready release process.
- `.env.example` with placeholders only; secure first-admin provisioning command and configuration validation.
- Setup guide for local/XAMPP and production; deployment, cron, SMTP and optional SMS instructions.
- Route/API documentation, role matrix, workflows, design tokens and dependency/license inventory.
- Tests and actual results, representative desktop/mobile screenshots, accessibility/performance findings and outstanding issues.
- Backup/restore, hosting/email migration, DNS cutover and rollback runbooks.
- CMS/admin user guide and a short handover/training walkthrough.
- A final checklist explicitly distinguishing completed implementation from production activation dependencies.

A feature is complete only when its UI, persistence, validation, authorization, business workflow and relevant verification are present. No dead buttons, success-only fake forms, hard-coded operational dashboard numbers, exposed secrets or silently omitted modules. Present exact setup/run commands and the remaining client decisions. Continue until the complete core platform is implemented and verified, even if production activation must wait for credentials or final authorization.

---

## Reference documentation for implementation

Verify current versions and compatibility directly from primary documentation:
- PHP supported versions: https://www.php.net/supported-versions.php
- Bootstrap customization: https://getbootstrap.com/docs/5.3/customize/color/
- Bootstrap colour modes: https://getbootstrap.com/docs/5.3/customize/color-modes/
- Alpine.js: https://alpinejs.dev/
- Chart.js: https://www.chartjs.org/docs/latest/

These references support the suggested frontend/runtime choices. They do not replace environment discovery or testing.

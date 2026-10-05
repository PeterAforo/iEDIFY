# Role and object-policy matrix

This is the approved baseline to implement and verify. The reusable foundation Policy is tested; role provisioning and full object-scoped domain policies are not complete yet. No UI hiding counts as authorization.

| Role | Permission family | Object scope | Required exclusions |
|---|---|---|---|
| Super administrator | Identity/configuration administration | Authorized system administration; sensitive access audited | No silent self-approval or conflict-of-interest bypass |
| Content editor | cms.read, cms.edit, media.manage | Public content drafts and classified public media | No publishing, applicant identity documents or finance |
| Content publisher | cms.review, cms.publish, cms.restore | Approved public content/revision workflow | No implicit operational records |
| Program manager | program.manage, application.decide, cohort.manage | Assigned programs/intakes/cohorts | No implicit funding/disbursement authority |
| Training coordinator | learning.manage, mentoring.coordinate | Assigned courses and approved participants | No unassigned applicant/financial information |
| Mentor | mentoring.assigned, mentoring.schedule | Accepted active assignments and explicitly shared fields | No unassigned profiles, private staff notes or identity documents |
| Application/funding reviewer | application.review or funding.review | Explicit assignment per submission type | No unassigned submissions, conflicting evaluations or self-approval |
| Funding approver | funding.approve, award.authorize | Versioned approval stages and limits | Evaluation/disbursement permissions remain separate |
| Finance officer | disbursement.authorize, disbursement.record, receipt.record | Authorized awards and organization receipts | No authority to create an award without approval |
| Impact/M&E officer | impact.edit, impact.submit; separate verify/publish grants | Assigned indicators/programs | Public approval and self-verification rules enforced separately |
| Community moderator | community.moderate | Assigned groups or explicit global moderator scope | No private application/funding access |
| Partner organization administrator | partner.members.manage, partner.shared.read | Own authorized organization and explicit shares | No widening shares, staff-role grants or internal relationship notes |
| Partner viewer | partner.shared.read | Active membership plus active record share | No other organization data, guessed downloads or revoked shares |
| Applicant/participant/founder | application.read_own, application.submit, learning.enrolled, startup.team | Own records, active enrollments and authorized startup team fields | Not teammates' personal documents or other applicants |
| Alumni | alumni.self, approved community grants | Own approved alumni record and explicit entitlements | No automatic access to historic cohorts or all operational records |

Privileged staff, mentor/reviewer and organization-administrator roles require verified email and completed MFA before privileged access. A user may have multiple roles but business constraints override permission unions. Queries, detail views, AJAX mutations, search, exports and file downloads must apply the same scope; exports recheck scope at generation and download.

Test each route with an anonymous user, unverified user, wrong role, right role/wrong object, correct assigned owner, deactivated account, revoked membership and privileged account without MFA. Sensitive screens distinguish applicant-facing feedback from internal notes through separate fields/read models.

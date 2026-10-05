# Route inventory

Generated from `app/Modules/*/routes.php` — regenerate after route changes.
Guards: `auth` requires sign-in, `privileged` requires an MFA-complete staff session,
`permission` enforces the named permission at the route and service layers,
`guest` is sign-in-only pages.

## CMS

| Method | Path | Handler | Guard |
|---|---|---|---|
| GET | `/admin` | DashboardController::index | privileged |
| GET | `/admin/content` | ContentAdminController::index | permission |
| GET | `/admin/content/{id:\d+}` | ContentAdminController::edit | permission |
| POST | `/admin/content/{id:\d+}/revise` | ContentAdminController::revise | permission |
| POST | `/admin/content/{id:\d+}/meta` | ContentAdminController::saveMeta | permission |
| POST | `/admin/content/{id:\d+}/submit-review` | ContentAdminController::submitReview | permission |
| POST | `/admin/content/{id:\d+}/publish` | ContentAdminController::publish | permission |
| POST | `/admin/content/{id:\d+}/schedule` | ContentAdminController::schedule | permission |
| GET | `/admin/settings` | ChromeAdminController::settings | permission |
| POST | `/admin/settings` | ChromeAdminController::saveSettings | permission |
| GET | `/admin/menus` | ChromeAdminController::menus | permission |
| POST | `/admin/menus` | ChromeAdminController::saveMenuItem | permission |
| POST | `/admin/menus/delete` | ChromeAdminController::deleteMenuItem | permission |
| GET | `/admin/hero` | ChromeAdminController::hero | permission |
| POST | `/admin/hero` | ChromeAdminController::saveHeroSlide | permission |
| POST | `/admin/hero/delete` | ChromeAdminController::deleteHeroSlide | permission |
| GET | `/admin/content/{id:\d+}/revisions` | ContentAdminController::revisions | permission |
| POST | `/admin/content/{id:\d+}/revisions/{number:\d+}/restore` | ContentAdminController::restore | permission |
| GET | `/admin/media` | MediaAdminController::index | permission |
| POST | `/admin/media/upload` | MediaAdminController::upload | permission |
| POST | `/admin/media/{id:\d+}/review` | MediaAdminController::review | permission |
| GET | `/admin/flags` | FlagController::index | permission |
| POST | `/admin/flags/{id:\d+}/resolve` | FlagController::resolve | permission |

## Community

| Method | Path | Handler | Guard |
|---|---|---|---|
| GET | `/events` | EventController::index |  |
| GET | `/events/{slug:[a-z0-9-]+}` | EventController::show |  |
| POST | `/events/{id:\d+}/register` | EventController::register | auth |
| POST | `/events/{id:\d+}/cancel` | EventController::cancel | auth |
| GET | `/opportunities` | EventController::opportunities |  |
| GET | `/opportunities/{id:\d+}` | EventController::opportunity |  |
| GET | `/community` | CommunityController::hub |  |
| GET | `/community/directory` | CommunityController::directory | permission |
| POST | `/community/profile` | CommunityController::saveProfile | permission |
| POST | `/community/groups` | CommunityController::createGroup | permission |
| GET | `/community/groups/{id:\d+}` | CommunityController::group | permission |
| POST | `/community/groups/{id:\d+}/join` | CommunityController::join | permission |
| POST | `/community/groups/{id:\d+}/posts` | CommunityController::post | permission |
| POST | `/community/groups/{id:\d+}/members/{userId:\d+}` | CommunityController::decideMember | permission |
| GET | `/community/posts/{id:\d+}` | CommunityController::postDetail | permission |
| POST | `/community/posts/{id:\d+}/comments` | CommunityController::comment | permission |
| POST | `/community/posts/{id:\d+}/bookmark` | CommunityController::bookmark | permission |
| POST | `/community/posts/{id:\d+}/report` | CommunityController::reportPost | permission |
| POST | `/community/comments/{id:\d+}/report` | CommunityController::reportComment | permission |
| GET | `/admin/moderation` | ModerationController::index | permission |
| POST | `/admin/moderation/{id:\d+}` | ModerationController::action | permission |
| POST | `/admin/events` | ModerationController::createEvent | permission |
| POST | `/admin/events/{id:\d+}/status` | ModerationController::eventStatus | permission |
| POST | `/admin/opportunities` | ModerationController::createOpportunity | permission |

## Engagement

| Method | Path | Handler | Guard |
|---|---|---|---|
| POST | `/enquiries` | EnquiryController::submit |  |
| GET | `/get-involved/{type:participant|mentor|volunteer|partner}` | InvolvementController::form |  |
| POST | `/get-involved/{type:participant|mentor|volunteer|partner}` | InvolvementController::submit |  |
| POST | `/newsletter/subscribe` | NewsletterController::subscribe |  |
| GET | `/newsletter/confirm/{token:[a-f0-9]{64}}` | NewsletterController::confirm |  |
| GET | `/newsletter/unsubscribe/{token:[a-f0-9]{64}}` | NewsletterController::unsubscribeForm |  |
| POST | `/newsletter/unsubscribe/{token:[a-f0-9]{64}}` | NewsletterController::unsubscribe |  |

## Funding

| Method | Path | Handler | Guard |
|---|---|---|---|
| GET | `/funding/{id:\d+}` | FundingRequestController::round |  |
| GET | `/account/funding` | FundingRequestController::mine | auth |
| POST | `/funding/rounds/{id:\d+}/requests` | FundingRequestController::submit | auth |
| GET | `/admin/funding` | FundingAdminController::index | permission |
| POST | `/admin/funding/rounds` | FundingAdminController::createRound | permission |
| GET | `/admin/funding/rounds/{id:\d+}` | FundingAdminController::round | permission |
| POST | `/admin/funding/rounds/{id:\d+}/status` | FundingAdminController::roundStatus | permission |
| POST | `/admin/funding/rounds/{id:\d+}/rules` | FundingAdminController::createRules | permission |
| POST | `/admin/funding/rounds/{id:\d+}/form` | FundingAdminController::setForm | permission |
| GET | `/admin/funding/requests/{id:\d+}` | FundingAdminController::request | permission |
| POST | `/admin/funding/requests/{id:\d+}/conflicts` | FundingAdminController::declareConflict | permission |
| POST | `/admin/funding/conflicts/{id:\d+}/clear` | FundingAdminController::clearConflict | permission |
| POST | `/admin/funding/requests/{id:\d+}/reviews` | FundingAdminController::review | permission |
| POST | `/admin/funding/requests/{id:\d+}/decision` | FundingAdminController::decide | permission |
| POST | `/admin/funding/requests/{id:\d+}/award` | FundingAdminController::award | permission |
| POST | `/admin/funding/awards/{id:\d+}/disbursements` | FundingAdminController::scheduleDisbursement | permission |
| POST | `/admin/funding/disbursements/{id:\d+}/authorize` | FundingAdminController::authorizeDisbursement | permission |
| POST | `/admin/funding/disbursements/{id:\d+}/record` | FundingAdminController::recordDisbursement | permission |
| POST | `/admin/funding/disbursements/{id:\d+}/cancel` | FundingAdminController::cancelDisbursement | permission |

## Identity

| Method | Path | Handler | Guard |
|---|---|---|---|
| GET | `/sign-in` | SignInController::form | guest |
| POST | `/sign-in` | SignInController::submit | guest |
| GET | `/sign-in/mfa` | MfaController::challengeForm |  |
| POST | `/sign-in/mfa` | MfaController::challenge |  |
| GET | `/sign-in/mfa/setup` | MfaController::setupForm |  |
| POST | `/sign-in/mfa/setup` | MfaController::setupSubmit |  |
| POST | `/sign-in/mfa/recovery` | MfaController::recover |  |
| GET | `/sign-up` | RegisterController::form | guest |
| POST | `/sign-up` | RegisterController::submit | guest |
| GET | `/sign-up/done` | RegisterController::done | guest |
| GET | `/verify-email/{token:[a-f0-9]{64}}` | VerifyEmailController::submit |  |
| GET | `/password/forgot` | PasswordResetController::requestForm | guest |
| POST | `/password/forgot` | PasswordResetController::request | guest |
| GET | `/password/reset/{token:[a-f0-9]{64}}` | PasswordResetController::form | guest |
| POST | `/password/reset/{token:[a-f0-9]{64}}` | PasswordResetController::submit | guest |
| POST | `/sign-out` | AccountController::signOut | auth |
| GET | `/account` | AccountController::index | auth |
| POST | `/account/email/resend` | AccountController::resendVerification | auth |
| GET | `/account/notifications` | AccountController::notifications | auth |
| POST | `/account/notifications/read` | AccountController::markNotificationRead | auth |
| POST | `/account/notifications/preferences` | AccountController::saveNotificationPreferences | auth |
| GET | `/account/security` | AccountController::security | auth |
| POST | `/account/security/mfa/begin` | AccountController::mfaBegin | auth |
| POST | `/account/security/mfa/confirm` | AccountController::mfaConfirm | auth |
| POST | `/account/data-request` | AccountController::dataRequest | auth |
| GET | `/admin/requests` | DataRequestController::index | permission |
| POST | `/admin/requests/{id:\d+}` | DataRequestController::decide | permission |
| GET | `/admin/invitations` | InvitationController::index | permission |
| POST | `/admin/invitations` | InvitationController::create | permission |
| GET | `/invite/{token:[a-f0-9]{64}}` | InvitationController::acceptForm | guest |
| POST | `/invite/{token:[a-f0-9]{64}}` | InvitationController::accept | guest |

## Impact

| Method | Path | Handler | Guard |
|---|---|---|---|
| GET | `/impact` | ImpactController::index |  |
| GET | `/impact/reports/{id:\d+}` | ImpactController::report |  |
| GET | `/admin/impact` | ImpactAdminController::index | permission |
| GET | `/admin/impact/export.csv` | ImpactAdminController::exportCsv | permission |
| POST | `/admin/impact/indicators` | ImpactAdminController::createIndicator | permission |
| POST | `/admin/impact/indicators/{id:\d+}/targets` | ImpactAdminController::setTarget | permission |
| POST | `/admin/impact/results` | ImpactAdminController::submitResult | permission |
| POST | `/admin/impact/results/{id:\d+}/verify` | ImpactAdminController::verifyResult | permission |
| POST | `/admin/impact/results/{id:\d+}/publish` | ImpactAdminController::publishResult | permission |
| POST | `/admin/impact/results/{id:\d+}/reject` | ImpactAdminController::rejectResult | permission |
| POST | `/admin/impact/reports` | ImpactAdminController::createReport | permission |
| POST | `/admin/impact/reports/{id:\d+}/approve` | ImpactAdminController::approveReport | permission |
| POST | `/admin/impact/reports/{id:\d+}/publish` | ImpactAdminController::publishReport | permission |

## Learning

| Method | Path | Handler | Guard |
|---|---|---|---|
| GET | `/learn` | LearnController::index | permission |
| GET | `/learn/{id:\d+}` | LearnController::show | permission |
| POST | `/learn/lessons/{id:\d+}/complete` | LearnController::complete | permission |
| GET | `/account/mentoring` | MentoringController::index | auth |
| POST | `/mentoring/profile` | MentoringController::saveProfile | permission |
| POST | `/mentoring/matches/{id:\d+}/respond` | MentoringController::respond | auth |
| POST | `/mentoring/matches/{id:\d+}/sessions` | MentoringController::schedule | permission |
| POST | `/mentoring/sessions/{id:\d+}/reschedule` | MentoringController::reschedule | permission |
| POST | `/mentoring/sessions/{id:\d+}/cancel` | MentoringController::cancel | permission |
| POST | `/mentoring/sessions/{id:\d+}/complete` | MentoringController::complete | permission |
| POST | `/mentoring/matches/propose` | MentoringController::propose | permission |
| GET | `/account/milestones` | MilestoneController::index | auth |
| POST | `/milestones/{id:\d+}/evidence` | MilestoneController::evidence | auth |
| POST | `/milestones` | MilestoneController::create | auth |
| GET | `/admin/learning` | LearningAdminController::index | permission |
| POST | `/admin/courses` | LearningAdminController::createCourse | permission |
| GET | `/admin/courses/{id:\d+}` | LearningAdminController::show | permission |
| POST | `/admin/courses/{id:\d+}/status` | LearningAdminController::setStatus | permission |
| POST | `/admin/courses/{id:\d+}/lessons` | LearningAdminController::addLesson | permission |
| POST | `/admin/courses/{id:\d+}/sessions` | LearningAdminController::addSession | permission |
| POST | `/admin/courses/{id:\d+}/enroll` | LearningAdminController::enroll | permission |
| POST | `/admin/sessions/{id:\d+}/attendance` | LearningAdminController::attendance | permission |
| GET | `/admin/milestones` | LearningAdminController::milestones | permission |
| POST | `/admin/milestones/{id:\d+}/review` | LearningAdminController::reviewMilestone | permission |

## Operations

| Method | Path | Handler | Guard |
|---|---|---|---|
| GET | `/health` | HealthController::show |  |
| GET | `/admin/outbox` | OutboxController::index | permission |
| POST | `/admin/outbox/{id:\d+}/retry` | OutboxController::retry | permission |

## Partners

| Method | Path | Handler | Guard |
|---|---|---|---|
| GET | `/partner` | PortalController::home | permission |
| GET | `/partner/reports/{id:\d+}` | PortalController::report | permission |
| GET | `/partner/documents/{id:\d+}` | PortalController::download | permission |
| POST | `/partner/proposals` | PortalController::createProposal | permission |
| POST | `/partner/proposals/{id:\d+}/status` | PortalController::proposalStatus | permission |
| POST | `/partner/members` | PortalController::addMember | permission |
| GET | `/admin/partners` | PartnerAdminController::index | permission |
| POST | `/admin/partners` | PartnerAdminController::createOrg | permission |
| GET | `/admin/partners/{id:\d+}` | PartnerAdminController::detail | permission |
| POST | `/admin/partners/{id:\d+}/members` | PartnerAdminController::addMember | permission |
| POST | `/admin/partners/{id:\d+}/members/{userId:\d+}/suspend` | PartnerAdminController::suspendMember | permission |
| POST | `/admin/partners/{id:\d+}/proposals` | PartnerAdminController::createProposal | permission |
| POST | `/admin/partners/proposals/{id:\d+}/status` | PartnerAdminController::proposalStatus | permission |
| POST | `/admin/partners/{id:\d+}/commitments` | PartnerAdminController::commitment | permission |
| POST | `/admin/partners/commitments/{id:\d+}/received` | PartnerAdminController::received | permission |
| POST | `/admin/partners/{id:\d+}/shares` | PartnerAdminController::share | permission |
| POST | `/admin/partners/{id:\d+}/shares/revoke` | PartnerAdminController::revoke | permission |
| POST | `/admin/partners/{id:\d+}/notes` | PartnerAdminController::note | permission |

## Programs

| Method | Path | Handler | Guard |
|---|---|---|---|
| GET | `/programs` | PublicProgramController::index |  |
| GET | `/programs/{slug:[a-z0-9-]+}` | PublicProgramController::show |  |
| GET | `/account/applications` | ApplyController::index | auth |
| GET | `/apply/{intakeId:\d+}` | ApplyController::form | permission |
| POST | `/apply/{intakeId:\d+}` | ApplyController::save | permission |
| POST | `/applications/{id:\d+}/submit` | ApplyController::submit | permission |
| POST | `/applications/{id:\d+}/withdraw` | ApplyController::withdraw | permission |
| POST | `/applications/{id:\d+}/respond` | ApplyController::respond | permission |
| POST | `/applications/{id:\d+}/documents/{key:[a-z0-9_]+}` | ApplyController::uploadDocument | permission |
| GET | `/admin/programs` | ProgramAdminController::index | permission |
| POST | `/admin/programs` | ProgramAdminController::create | permission |
| GET | `/admin/programs/{id:\d+}` | ProgramAdminController::show | permission |
| POST | `/admin/programs/{id:\d+}/status` | ProgramAdminController::setStatus | permission |
| POST | `/admin/programs/{id:\d+}/intakes` | ProgramAdminController::createIntake | permission |
| POST | `/admin/intakes/{id:\d+}/status` | ProgramAdminController::setIntakeStatus | permission |
| POST | `/admin/intakes/{id:\d+}/forms` | ProgramAdminController::createForm | permission |
| POST | `/admin/programs/{id:\d+}/cohorts` | ProgramAdminController::createCohort | permission |
| GET | `/admin/applications` | ApplicationAdminController::index | permission |
| GET | `/admin/applications/export.csv` | ApplicationAdminController::exportCsv | permission |
| GET | `/admin/applications/{id:\d+}` | ApplicationAdminController::show | permission |
| POST | `/admin/applications/{id:\d+}/transition` | ApplicationAdminController::transition | privileged |
| POST | `/admin/applications/{id:\d+}/assign` | ApplicationAdminController::assign | permission |
| POST | `/admin/applications/{id:\d+}/review` | ApplicationAdminController::review | permission |
| POST | `/admin/applications/{id:\d+}/enroll` | ApplicationAdminController::enroll | permission |

## Web

| Method | Path | Handler | Guard |
|---|---|---|---|
| GET | `/` | PageController::home |  |
| GET | `/media/{id:\d+}` | MediaController::serve |  |
| GET | `/search` | SiteController::search |  |
| GET | `/news` | SiteController::news |  |
| GET | `/publications` | SiteController::publications |  |
| GET | `/faq` | SiteController::faq |  |
| GET | `/auth/{page:sign-in|sign-up}` | SiteController::redirectAuth |  |
| GET | `/robots.txt` | SiteController::robots |  |
| GET | `/sitemap.xml` | SiteController::sitemap |  |
| GET | `/preview/{id:\d+}/{token:[a-f0-9]{64}}` | PageController::preview |  |
| GET | `/{slug:[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*}` | PageController::show |  |

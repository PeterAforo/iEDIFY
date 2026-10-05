# Entity–relationship map

Authoritative for the implemented schema. Tables are grouped by domain; arrows
show foreign-key direction (`child → parent`). Reconcile against
`database/migrations/` — the last reconciled migration is
`20261005000820_site_chrome` (88 base tables).

## Identity & access

- `users` 1—N `user_roles` → `roles`; `roles` N—N `permissions` via `role_permissions`
- `users` 1—N `auth_tokens` (verification/reset), `mfa_factors` (1—1), `mfa_recovery_codes` (1—N)
- `users` 1—N `sessions`; `sessions.user_id` nullable (guest rows)
- `users` 1—N `consents` (append-only, `policy_version` recorded)
- `users` 1—N `participant_profiles` (1—1), `member_profiles`
- `invitations` → `roles`, `users` (`invited_by`); hashed single-use tokens
- `data_requests` → `users` (`user_id`, `decided_by`)
- `users` 1—N `notifications`; `notification_preferences` keyed `(user_id, scope)`
- `audit_events.actor_id` → `users` (nullable)

## CMS & imports

- `content_items` 1—N `content_revisions`; `content_items.published_revision_id` → `(content_id, id)` composite FK; `publish_at`/`scheduled_by` drive cron publication
- `content_revisions` → `source_records`, `users` (`author_id`)
- `team_members` → `content_items`, `media_assets` (`portrait_asset_id`)
- `import_runs` 1—N `source_records`; `source_mappings` → `source_records`, `content_items`, `media_assets`, `import_runs`
- `editorial_flags` → `source_records` (joined to content via `source_mappings` — gates publish)
- `publication_events` → `content_items`+`content_revisions`, `users`
- `media_assets` standalone (referenced by many entities)
- `site_settings`, `navigation_items` (`menu ∈ main|footer`), `hero_slides` → `media_assets`

## Engagement

- `enquiries` → `users` (nullable)
- `newsletter_subscriptions` 1—N `newsletter_tokens`

## Programs & applications

- `programs` 1—N `cohorts` 1—N `cohort_members` → `users`
- `programs`/`cohorts` 1—N `intakes` 1—N `application_forms` (versioned) and `applications`
- `applications` → `application_forms`, `users`; 1—N `application_documents` → `media_assets`, `application_reviews`, `application_status_history`, `review_assignments` → `users`
- `startups` 1—N `startup_members` → `users`; `milestones` → `startups` or `users`, `media_assets` (evidence), `users` (`reviewed_by`)

## Learning & mentoring

- `courses` 1—N `course_lessons`, `course_sessions`, `course_enrollments` → `users`
- `course_sessions` 1—N `attendance_records` → `users`; `lesson_progress` → `course_lessons`, `users`
- `mentor_profiles` → `users`; `mentor_matches` → `users`×2; `mentor_sessions` → `mentor_matches`

## Community & events

- `events` 1—N `event_registrations` → `users`; `opportunities` standalone
- `community_groups` 1—N `group_members` → `users`, `community_posts` → `users`
- `community_posts` 1—N `community_comments` → `users`, `bookmarks` → `users`
- `moderation_reports` → `users` (reporter/assignee)

## Funding

- `funding_rounds` (`form_schema` JSON) 1—N `funding_rule_versions` (immutable scorecards)
- `funding_requests` → `funding_rounds`, `users`, `startups`, `funding_rule_versions` (frozen), `media_assets` (`budget_media_id`), `answers` JSON
- `funding_requests` 1—N `funding_reviews`/`funding_conflicts` → `users`; 1—1 `funding_approvals` → `users`
- `funding_requests` 1—1 `awards` 1—N `disbursements` (authorized/recorded by different users)

## Impact

- `impact_indicators` 1—N `impact_targets`, `impact_results` → `programs`, `cohorts`, `users` (submitted/verified/published — different actors enforced), `media_assets` (evidence)
- `impact_reports` → `users` (`created_by`, `approved_by`)

## Partners

- `partner_organizations` 1—N `partner_memberships` → `users`, `partner_proposals`, `partner_commitments`, `partner_received`, `partner_notes`, `partner_shares` (org-scoped resources)
- `partner_access_log` → `partner_organizations`, `users` (download audit)

## Operations

- `outbox_events` (unique `event_key`; lease/attempts/backoff state machine)
- `rate_limit_buckets`, `import_runs`, `schema_migrations`

## Retention notes

`retention:purge` clears expired `sessions`, `rate_limit_buckets`, consumed
`auth_tokens`, old completed `outbox_events` and read `notifications`.
Completed `data_requests` deletions deactivate `users.status`; audit and
financial rows (funding, disbursements, consents) are retained per policy.

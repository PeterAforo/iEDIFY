<?php

declare(strict_types=1);

return [
    'super_administrator' => ['privileged' => true, 'permissions' => ['identity.manage', 'settings.manage', 'operations.manage', 'cms.edit', 'cms.review', 'cms.publish', 'cms.restore', 'media.manage', 'program.manage', 'application.decide', 'application.review', 'cohort.manage', 'learning.manage', 'mentoring.coordinate', 'community.moderate', 'funding.manage', 'funding.review', 'funding.approve', 'award.authorize', 'disbursement.authorize', 'disbursement.record', 'receipt.record', 'impact.edit', 'impact.submit', 'impact.verify', 'impact.publish', 'partner.manage', 'export.run']],
    'content_editor' => ['privileged' => true, 'permissions' => ['cms.edit', 'media.manage']],
    'content_publisher' => ['privileged' => true, 'permissions' => ['cms.edit', 'cms.review', 'cms.publish', 'cms.restore', 'media.manage']],
    'program_manager' => ['privileged' => true, 'permissions' => ['program.manage', 'application.decide', 'cohort.manage', 'funding.manage', 'impact.verify', 'impact.publish', 'partner.manage', 'export.run']],
    'training_coordinator' => ['privileged' => true, 'permissions' => ['learning.manage', 'mentoring.coordinate']],
    'mentor' => ['privileged' => true, 'permissions' => ['mentoring.assigned', 'mentoring.schedule']],
    'reviewer' => ['privileged' => true, 'permissions' => ['application.review', 'funding.review']],
    'funding_approver' => ['privileged' => true, 'permissions' => ['funding.approve', 'award.authorize']],
    'finance_officer' => ['privileged' => true, 'permissions' => ['disbursement.authorize', 'disbursement.record', 'receipt.record']],
    'impact_officer' => ['privileged' => true, 'permissions' => ['impact.edit', 'impact.submit', 'export.run']],
    'community_moderator' => ['privileged' => true, 'permissions' => ['community.moderate']],
    'partner_administrator' => ['privileged' => true, 'permissions' => ['partner.members.manage', 'partner.shared.read']],
    'partner_viewer' => ['privileged' => false, 'permissions' => ['partner.shared.read']],
    'participant' => ['privileged' => false, 'permissions' => ['application.read_own', 'application.submit', 'learning.enrolled', 'startup.team', 'community.member']],
    'alumni' => ['privileged' => false, 'permissions' => ['alumni.self', 'community.member']],
];

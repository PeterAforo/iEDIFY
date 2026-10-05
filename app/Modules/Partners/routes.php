<?php

declare(strict_types=1);

use IEdify\Modules\Partners\Http\Admin\PartnerAdminController;
use IEdify\Modules\Partners\Http\PortalController;

return [
    ['GET', '/partner', [PortalController::class, 'home'], ['permission' => 'partner.shared.read']],
    ['GET', '/partner/reports/{id:\d+}', [PortalController::class, 'report'], ['permission' => 'partner.shared.read']],
    ['GET', '/partner/documents/{id:\d+}', [PortalController::class, 'download'], ['permission' => 'partner.shared.read']],
    ['POST', '/partner/proposals', [PortalController::class, 'createProposal'], ['permission' => 'partner.shared.read']],
    ['POST', '/partner/proposals/{id:\d+}/status', [PortalController::class, 'proposalStatus'], ['permission' => 'partner.shared.read']],
    ['POST', '/partner/members', [PortalController::class, 'addMember'], ['permission' => 'partner.members.manage']],
    ['GET', '/admin/partners', [PartnerAdminController::class, 'index'], ['permission' => 'partner.manage']],
    ['POST', '/admin/partners', [PartnerAdminController::class, 'createOrg'], ['permission' => 'partner.manage']],
    ['GET', '/admin/partners/{id:\d+}', [PartnerAdminController::class, 'detail'], ['permission' => 'partner.manage']],
    ['POST', '/admin/partners/{id:\d+}/members', [PartnerAdminController::class, 'addMember'], ['permission' => 'partner.manage']],
    ['POST', '/admin/partners/{id:\d+}/members/{userId:\d+}/suspend', [PartnerAdminController::class, 'suspendMember'], ['permission' => 'partner.manage']],
    ['POST', '/admin/partners/{id:\d+}/proposals', [PartnerAdminController::class, 'createProposal'], ['permission' => 'partner.manage']],
    ['POST', '/admin/partners/proposals/{id:\d+}/status', [PartnerAdminController::class, 'proposalStatus'], ['permission' => 'partner.manage']],
    ['POST', '/admin/partners/{id:\d+}/commitments', [PartnerAdminController::class, 'commitment'], ['permission' => 'partner.manage']],
    ['POST', '/admin/partners/commitments/{id:\d+}/received', [PartnerAdminController::class, 'received'], ['permission' => 'partner.manage']],
    ['POST', '/admin/partners/{id:\d+}/shares', [PartnerAdminController::class, 'share'], ['permission' => 'partner.manage']],
    ['POST', '/admin/partners/{id:\d+}/shares/revoke', [PartnerAdminController::class, 'revoke'], ['permission' => 'partner.manage']],
    ['POST', '/admin/partners/{id:\d+}/notes', [PartnerAdminController::class, 'note'], ['permission' => 'partner.manage']],
];

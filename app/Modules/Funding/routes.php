<?php

declare(strict_types=1);

use IEdify\Modules\Funding\Http\Admin\FundingAdminController;
use IEdify\Modules\Funding\Http\FundingRequestController;

return [
    ['GET', '/funding/{id:\d+}', [FundingRequestController::class, 'round']],
    ['GET', '/account/funding', [FundingRequestController::class, 'mine'], ['auth' => true]],
    ['POST', '/funding/rounds/{id:\d+}/requests', [FundingRequestController::class, 'submit'], ['auth' => true]],
    ['GET', '/admin/funding', [FundingAdminController::class, 'index'], ['permission' => 'funding.review']],
    ['POST', '/admin/funding/rounds', [FundingAdminController::class, 'createRound'], ['permission' => 'funding.manage']],
    ['GET', '/admin/funding/rounds/{id:\d+}', [FundingAdminController::class, 'round'], ['permission' => 'funding.review']],
    ['POST', '/admin/funding/rounds/{id:\d+}/status', [FundingAdminController::class, 'roundStatus'], ['permission' => 'funding.manage']],
    ['POST', '/admin/funding/rounds/{id:\d+}/rules', [FundingAdminController::class, 'createRules'], ['permission' => 'funding.manage']],
    ['GET', '/admin/funding/requests/{id:\d+}', [FundingAdminController::class, 'request'], ['permission' => 'funding.review']],
    ['POST', '/admin/funding/requests/{id:\d+}/conflicts', [FundingAdminController::class, 'declareConflict'], ['permission' => 'funding.review']],
    ['POST', '/admin/funding/conflicts/{id:\d+}/clear', [FundingAdminController::class, 'clearConflict'], ['permission' => 'funding.manage']],
    ['POST', '/admin/funding/requests/{id:\d+}/reviews', [FundingAdminController::class, 'review'], ['permission' => 'funding.review']],
    ['POST', '/admin/funding/requests/{id:\d+}/decision', [FundingAdminController::class, 'decide'], ['permission' => 'funding.approve']],
    ['POST', '/admin/funding/requests/{id:\d+}/award', [FundingAdminController::class, 'award'], ['permission' => 'award.authorize']],
    ['POST', '/admin/funding/awards/{id:\d+}/disbursements', [FundingAdminController::class, 'scheduleDisbursement'], ['permission' => 'disbursement.record']],
    ['POST', '/admin/funding/disbursements/{id:\d+}/authorize', [FundingAdminController::class, 'authorizeDisbursement'], ['permission' => 'disbursement.authorize']],
    ['POST', '/admin/funding/disbursements/{id:\d+}/record', [FundingAdminController::class, 'recordDisbursement'], ['permission' => 'disbursement.record']],
    ['POST', '/admin/funding/disbursements/{id:\d+}/cancel', [FundingAdminController::class, 'cancelDisbursement'], ['permission' => 'disbursement.authorize']],
];

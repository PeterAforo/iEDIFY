<?php

declare(strict_types=1);

use IEdify\Modules\CMS\Http\Admin\ChromeAdminController;
use IEdify\Modules\CMS\Http\Admin\ContentAdminController;
use IEdify\Modules\CMS\Http\Admin\DashboardController;
use IEdify\Modules\CMS\Http\Admin\FlagController;
use IEdify\Modules\CMS\Http\Admin\MediaAdminController;

return [
    ['GET', '/admin', [DashboardController::class, 'index'], ['privileged' => true]],
    ['GET', '/admin/content', [ContentAdminController::class, 'index'], ['permission' => 'cms.edit']],
    ['GET', '/admin/content/{id:\d+}', [ContentAdminController::class, 'edit'], ['permission' => 'cms.edit']],
    ['POST', '/admin/content/{id:\d+}/revise', [ContentAdminController::class, 'revise'], ['permission' => 'cms.edit']],
    ['POST', '/admin/content/{id:\d+}/meta', [ContentAdminController::class, 'saveMeta'], ['permission' => 'cms.edit']],
    ['POST', '/admin/content/{id:\d+}/submit-review', [ContentAdminController::class, 'submitReview'], ['permission' => 'cms.edit']],
    ['POST', '/admin/content/{id:\d+}/publish', [ContentAdminController::class, 'publish'], ['permission' => 'cms.publish']],
    ['POST', '/admin/content/{id:\d+}/schedule', [ContentAdminController::class, 'schedule'], ['permission' => 'cms.edit']],
    ['GET', '/admin/settings', [ChromeAdminController::class, 'settings'], ['permission' => 'cms.edit']],
    ['POST', '/admin/settings', [ChromeAdminController::class, 'saveSettings'], ['permission' => 'cms.edit']],
    ['GET', '/admin/menus', [ChromeAdminController::class, 'menus'], ['permission' => 'cms.edit']],
    ['POST', '/admin/menus', [ChromeAdminController::class, 'saveMenuItem'], ['permission' => 'cms.edit']],
    ['POST', '/admin/menus/delete', [ChromeAdminController::class, 'deleteMenuItem'], ['permission' => 'cms.edit']],
    ['GET', '/admin/hero', [ChromeAdminController::class, 'hero'], ['permission' => 'cms.edit']],
    ['POST', '/admin/hero', [ChromeAdminController::class, 'saveHeroSlide'], ['permission' => 'cms.edit']],
    ['POST', '/admin/hero/delete', [ChromeAdminController::class, 'deleteHeroSlide'], ['permission' => 'cms.edit']],
    ['GET', '/admin/content/{id:\d+}/revisions', [ContentAdminController::class, 'revisions'], ['permission' => 'cms.edit']],
    ['POST', '/admin/content/{id:\d+}/revisions/{number:\d+}/restore', [ContentAdminController::class, 'restore'], ['permission' => 'cms.restore']],
    ['GET', '/admin/media', [MediaAdminController::class, 'index'], ['permission' => 'media.manage']],
    ['POST', '/admin/media/upload', [MediaAdminController::class, 'upload'], ['permission' => 'media.manage']],
    ['POST', '/admin/media/{id:\d+}/review', [MediaAdminController::class, 'review'], ['permission' => 'media.manage']],
    ['GET', '/admin/flags', [FlagController::class, 'index'], ['permission' => 'cms.review']],
    ['POST', '/admin/flags/{id:\d+}/resolve', [FlagController::class, 'resolve'], ['permission' => 'cms.review']],
];

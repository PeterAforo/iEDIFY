<?php

declare(strict_types=1);

use IEdify\Modules\Web\Http\MediaController;
use IEdify\Modules\Web\Http\PageController;
use IEdify\Modules\Web\Http\SiteController;

return [
    ['GET', '/', [PageController::class, 'home']],
    ['GET', '/media/{id:\d+}', [MediaController::class, 'serve']],
    ['GET', '/search', [SiteController::class, 'search']],
    ['GET', '/auth/{page:sign-in|sign-up}', [SiteController::class, 'redirectAuth']],
    ['GET', '/robots.txt', [SiteController::class, 'robots']],
    ['GET', '/sitemap.xml', [SiteController::class, 'sitemap']],
    ['GET', '/{slug:[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*}', [PageController::class, 'show']],
];

<?php

declare(strict_types=1);

use IEdify\Modules\Community\Http\Admin\ModerationController;
use IEdify\Modules\Community\Http\CommunityController;
use IEdify\Modules\Community\Http\EventController;

return [
    ['GET', '/events', [EventController::class, 'index']],
    ['GET', '/events/{slug:[a-z0-9-]+}', [EventController::class, 'show']],
    ['POST', '/events/{id:\d+}/register', [EventController::class, 'register'], ['auth' => true]],
    ['POST', '/events/{id:\d+}/cancel', [EventController::class, 'cancel'], ['auth' => true]],
    ['GET', '/community', [CommunityController::class, 'hub']],
    ['GET', '/community/directory', [CommunityController::class, 'directory'], ['permission' => 'community.member']],
    ['POST', '/community/profile', [CommunityController::class, 'saveProfile'], ['permission' => 'community.member']],
    ['POST', '/community/groups', [CommunityController::class, 'createGroup'], ['permission' => 'community.member']],
    ['GET', '/community/groups/{id:\d+}', [CommunityController::class, 'group'], ['permission' => 'community.member']],
    ['POST', '/community/groups/{id:\d+}/join', [CommunityController::class, 'join'], ['permission' => 'community.member']],
    ['POST', '/community/groups/{id:\d+}/posts', [CommunityController::class, 'post'], ['permission' => 'community.member']],
    ['POST', '/community/groups/{id:\d+}/members/{userId:\d+}', [CommunityController::class, 'decideMember'], ['permission' => 'community.member']],
    ['GET', '/community/posts/{id:\d+}', [CommunityController::class, 'postDetail'], ['permission' => 'community.member']],
    ['POST', '/community/posts/{id:\d+}/comments', [CommunityController::class, 'comment'], ['permission' => 'community.member']],
    ['POST', '/community/posts/{id:\d+}/bookmark', [CommunityController::class, 'bookmark'], ['permission' => 'community.member']],
    ['POST', '/community/posts/{id:\d+}/report', [CommunityController::class, 'reportPost'], ['permission' => 'community.member']],
    ['POST', '/community/comments/{id:\d+}/report', [CommunityController::class, 'reportComment'], ['permission' => 'community.member']],
    ['GET', '/admin/moderation', [ModerationController::class, 'index'], ['permission' => 'community.moderate']],
    ['POST', '/admin/moderation/{id:\d+}', [ModerationController::class, 'action'], ['permission' => 'community.moderate']],
    ['POST', '/admin/events', [ModerationController::class, 'createEvent'], ['permission' => 'program.manage']],
    ['POST', '/admin/events/{id:\d+}/status', [ModerationController::class, 'eventStatus'], ['permission' => 'program.manage']],
];

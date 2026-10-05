<?php

declare(strict_types=1);

use IEdify\Modules\Learning\Http\Admin\LearningAdminController;
use IEdify\Modules\Learning\Http\LearnController;
use IEdify\Modules\Learning\Http\MentoringController;
use IEdify\Modules\Learning\Http\MilestoneController;

return [
    ['GET', '/learn', [LearnController::class, 'index'], ['permission' => 'learning.enrolled']],
    ['GET', '/learn/{id:\d+}', [LearnController::class, 'show'], ['permission' => 'learning.enrolled']],
    ['POST', '/learn/lessons/{id:\d+}/complete', [LearnController::class, 'complete'], ['permission' => 'learning.enrolled']],
    ['GET', '/account/mentoring', [MentoringController::class, 'index'], ['auth' => true]],
    ['POST', '/mentoring/profile', [MentoringController::class, 'saveProfile'], ['permission' => 'mentoring.assigned']],
    ['POST', '/mentoring/matches/{id:\d+}/respond', [MentoringController::class, 'respond'], ['auth' => true]],
    ['POST', '/mentoring/matches/{id:\d+}/sessions', [MentoringController::class, 'schedule'], ['permission' => 'mentoring.schedule']],
    ['POST', '/mentoring/sessions/{id:\d+}/reschedule', [MentoringController::class, 'reschedule'], ['permission' => 'mentoring.schedule']],
    ['POST', '/mentoring/sessions/{id:\d+}/cancel', [MentoringController::class, 'cancel'], ['permission' => 'mentoring.schedule']],
    ['POST', '/mentoring/sessions/{id:\d+}/complete', [MentoringController::class, 'complete'], ['permission' => 'mentoring.assigned']],
    ['POST', '/mentoring/matches/propose', [MentoringController::class, 'propose'], ['permission' => 'mentoring.coordinate']],
    ['GET', '/account/milestones', [MilestoneController::class, 'index'], ['auth' => true]],
    ['POST', '/milestones/{id:\d+}/evidence', [MilestoneController::class, 'evidence'], ['auth' => true]],
    ['POST', '/milestones', [MilestoneController::class, 'create'], ['auth' => true]],
    ['GET', '/admin/learning', [LearningAdminController::class, 'index'], ['permission' => 'learning.manage']],
    ['POST', '/admin/courses', [LearningAdminController::class, 'createCourse'], ['permission' => 'learning.manage']],
    ['GET', '/admin/courses/{id:\d+}', [LearningAdminController::class, 'show'], ['permission' => 'learning.manage']],
    ['POST', '/admin/courses/{id:\d+}/status', [LearningAdminController::class, 'setStatus'], ['permission' => 'learning.manage']],
    ['POST', '/admin/courses/{id:\d+}/lessons', [LearningAdminController::class, 'addLesson'], ['permission' => 'learning.manage']],
    ['POST', '/admin/courses/{id:\d+}/sessions', [LearningAdminController::class, 'addSession'], ['permission' => 'learning.manage']],
    ['POST', '/admin/courses/{id:\d+}/enroll', [LearningAdminController::class, 'enroll'], ['permission' => 'learning.manage']],
    ['POST', '/admin/sessions/{id:\d+}/attendance', [LearningAdminController::class, 'attendance'], ['permission' => 'learning.manage']],
    ['GET', '/admin/milestones', [LearningAdminController::class, 'milestones'], ['permission' => 'program.manage']],
    ['POST', '/admin/milestones/{id:\d+}/review', [LearningAdminController::class, 'reviewMilestone'], ['permission' => 'program.manage']],
];

<?php

declare(strict_types=1);

use IEdify\Modules\Engagement\Http\EnquiryController;
use IEdify\Modules\Engagement\Http\NewsletterController;

return [
    ['POST', '/enquiries', [EnquiryController::class, 'submit']],
    ['POST', '/newsletter/subscribe', [NewsletterController::class, 'subscribe']],
    ['GET', '/newsletter/confirm/{token:[a-f0-9]{64}}', [NewsletterController::class, 'confirm']],
    ['GET', '/newsletter/unsubscribe/{token:[a-f0-9]{64}}', [NewsletterController::class, 'unsubscribeForm']],
    ['POST', '/newsletter/unsubscribe/{token:[a-f0-9]{64}}', [NewsletterController::class, 'unsubscribe']],
];

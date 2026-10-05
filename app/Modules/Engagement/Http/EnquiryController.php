<?php

declare(strict_types=1);

namespace IEdify\Modules\Engagement\Http;

use IEdify\Core\Http\Controller;
use IEdify\Modules\Engagement\Services\EnquiryService;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

final class EnquiryController extends Controller
{
    public function submit(): Response
    {
        $this->throttle('enquiry.submit', 10, 3600);
        if ($this->input('fax') !== '' || $this->input('department') !== '') {
            $this->flash('success', 'Thank you. Your message has been received.');
            return $this->redirect('/contact');
        }
        try {
            (new EnquiryService($this->app->pdo()))->submit(
                $this->input('name'),
                $this->input('email'),
                $this->input('subject'),
                (string) $this->request->request->get('message', ''),
                $this->actor()?->id,
            );
        } catch (InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
            return $this->redirect('/contact');
        }
        $this->flash('success', 'Thank you. Your message has been received and we will reply by email.');
        return $this->redirect('/contact');
    }
}

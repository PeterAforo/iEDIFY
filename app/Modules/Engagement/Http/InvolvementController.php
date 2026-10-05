<?php

declare(strict_types=1);

namespace IEdify\Modules\Engagement\Http;

use IEdify\Core\Http\Controller;
use IEdify\Modules\Engagement\Services\EnquiryService;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Get involved" interest forms (mentor, volunteer, partner). They persist
 * through the same validated enquiry pipeline as the contact form — no
 * separate trust boundary.
 */
final class InvolvementController extends Controller
{
    private const TYPES = [
        'participant' => 'Participant interest',
        'mentor' => 'Mentor interest',
        'volunteer' => 'Volunteer interest',
        'partner' => 'Partnership interest',
    ];

    public function form(): Response
    {
        $type = $this->type();
        return $this->render('public/get-involved.twig', ['type' => $type, 'label' => self::TYPES[$type]]);
    }

    public function submit(): Response
    {
        $type = $this->type();
        $this->throttle('enquiry.submit', 10, 3600);
        if ($this->input('fax') !== '' || $this->input('department') !== '') {
            $this->flash('success', 'Thank you. Your interest has been received.');
            return $this->redirect('/get-involved/' . $type);
        }
        $background = trim((string) $this->request->request->get('background', ''));
        $message = self::TYPES[$type] . ".\n\n" . $background;
        try {
            (new EnquiryService($this->app->pdo()))->submit(
                $this->input('name'),
                $this->input('email'),
                self::TYPES[$type],
                $message,
                $this->actor()?->id,
            );
        } catch (InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
            return $this->redirect('/get-involved/' . $type);
        }
        $this->flash('success', 'Thank you. Your interest has been received and the team will follow up by email.');
        return $this->redirect('/get-involved/' . $type);
    }

    private function type(): string
    {
        $type = (string) $this->vars['type'];
        if (!isset(self::TYPES[$type])) {
            throw new \IEdify\Core\Http\HttpError(404, 'This page is not available.');
        }
        return $type;
    }
}

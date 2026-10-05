<?php

declare(strict_types=1);

namespace IEdify\Modules\CMS\Http\Admin;

use IEdify\Core\Http\Controller;
use IEdify\Core\Http\HttpError;
use IEdify\Modules\CMS\Services\SiteChromeService;
use Symfony\Component\HttpFoundation\Response;

/** Site chrome administration: contact/social settings, menus and hero slides. */
final class ChromeAdminController extends Controller
{
    public function settings(): Response
    {
        return $this->render('admin/chrome/settings.twig', ['settings' => $this->service()->settings()]);
    }

    public function saveSettings(): Response
    {
        try {
            $this->service()->updateSettings($this->requireActor(), (array) $this->request->request->all('settings'));
            $this->flash('success', 'Settings saved.');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/admin/settings');
    }

    public function menus(): Response
    {
        return $this->render('admin/chrome/menus.twig', ['items' => $this->service()->navigationForAdmin()]);
    }

    public function saveMenuItem(): Response
    {
        try {
            $id = $this->request->request->get('id');
            $this->service()->saveNavigationItem(
                $this->requireActor(),
                $id !== null && $id !== '' ? (int) $id : null,
                (string) $this->request->request->get('menu', ''),
                $this->input('label'),
                $this->input('url'),
                (int) $this->request->request->get('sort', 0),
                $this->request->request->get('enabled') === '1',
            );
            $this->flash('success', 'Navigation saved.');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/admin/menus');
    }

    public function deleteMenuItem(): Response
    {
        $this->service()->deleteNavigationItem($this->requireActor(), (int) $this->request->request->get('id', 0));
        $this->flash('success', 'Navigation item removed.');
        return $this->redirect('/admin/menus');
    }

    public function hero(): Response
    {
        return $this->render('admin/chrome/hero.twig', ['slides' => $this->service()->heroSlides(false)]);
    }

    public function saveHeroSlide(): Response
    {
        try {
            $id = $this->request->request->get('id');
            $media = $this->request->request->get('media_id');
            $this->service()->saveHeroSlide(
                $this->requireActor(),
                $id !== null && $id !== '' ? (int) $id : null,
                (string) $this->request->request->get('kicker', ''),
                $this->input('title'),
                (string) $this->request->request->get('body', ''),
                (string) $this->request->request->get('cta_label', ''),
                (string) $this->request->request->get('cta_url', ''),
                $media !== null && $media !== '' ? (int) $media : null,
                (int) $this->request->request->get('sort', 0),
                $this->request->request->get('enabled') === '1',
            );
            $this->flash('success', 'Slide saved.');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/admin/hero');
    }

    public function deleteHeroSlide(): Response
    {
        $this->service()->deleteHeroSlide($this->requireActor(), (int) $this->request->request->get('id', 0));
        $this->flash('success', 'Slide removed.');
        return $this->redirect('/admin/hero');
    }

    private function service(): SiteChromeService
    {
        return new SiteChromeService($this->app->pdo());
    }
}

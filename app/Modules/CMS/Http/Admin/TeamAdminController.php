<?php

declare(strict_types=1);

namespace IEdify\Modules\CMS\Http\Admin;

use IEdify\Core\Http\Controller;
use IEdify\Core\Http\HttpError;
use IEdify\Modules\CMS\Services\TeamService;
use Symfony\Component\HttpFoundation\Response;

/** Team roster administration: member list, create, edit, remove. */
final class TeamAdminController extends Controller
{
    public function index(): Response
    {
        $members = $this->service()->roster();
        $groups = [];
        foreach ($members as $member) {
            $groups[$member['roster_group']][] = $member;
        }
        return $this->render('admin/team/index.twig', [
            'groups' => $groups,
            'group_labels' => TeamService::GROUPS,
        ]);
    }

    public function newForm(): Response
    {
        return $this->render('admin/team/new.twig', ['group_labels' => TeamService::GROUPS]);
    }

    public function create(): Response
    {
        try {
            $id = $this->service()->create(
                $this->requireActor(),
                $this->input('display_name'),
                $this->input('role_label'),
                (string) $this->request->request->get('roster_group', ''),
                $this->request->request->get('youth_adviser') === '1',
                (int) $this->request->request->get('display_order', 0),
                $this->mediaId(),
            );
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
            return $this->redirect('/admin/team/new');
        }
        $this->flash('success', 'Team member created. Complete their bio below.');
        return $this->redirect('/admin/team/' . $id);
    }

    public function edit(): Response
    {
        $member = $this->service()->member((int) $this->vars['id']);
        $statement = $this->app->pdo()->prepare('SELECT * FROM content_revisions WHERE content_id = ? ORDER BY revision_number DESC LIMIT 1');
        $statement->execute([(int) $member['content_id']]);
        $latest = $statement->fetch();
        return $this->render('admin/team/edit.twig', [
            'member' => $member,
            'latest' => $latest === false ? null : $latest,
            'group_labels' => TeamService::GROUPS,
            'media' => $this->mediaByIds($member['portrait_asset_id'] !== null ? [(int) $member['portrait_asset_id']] : []),
        ]);
    }

    public function save(): Response
    {
        $member = $this->service()->member((int) $this->vars['id']);
        $actor = $this->requireActor();
        try {
            $this->service()->update(
                $actor,
                (int) $member['id'],
                $this->input('display_name'),
                $this->input('role_label'),
                (string) $this->request->request->get('roster_group', ''),
                $this->request->request->get('youth_adviser') === '1',
                (int) $this->request->request->get('display_order', 0),
                $this->mediaId(),
            );
            $sections = json_decode((string) $this->request->request->get('sections', ''), true, 512, JSON_THROW_ON_ERROR);
            $this->app->cms()->revise($actor, (int) $member['content_id'], (int) $this->request->request->get('version'), $this->input('display_name'), $sections);
        } catch (\JsonException) {
            $this->flash('error', 'Bio sections must be valid JSON.');
            return $this->redirect('/admin/team/' . (int) $member['id']);
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
            return $this->redirect('/admin/team/' . (int) $member['id']);
        }
        $this->flash('success', 'Team member saved.');
        return $this->redirect('/admin/team/' . (int) $member['id']);
    }

    public function delete(): Response
    {
        $member = $this->service()->member((int) $this->vars['id']);
        try {
            $this->service()->delete($this->requireActor(), (int) $member['id']);
            $this->flash('success', 'Team member removed.');
            return $this->redirect('/admin/team');
        } catch (HttpError $error) {
            $this->flash('error', $error->getMessage());
            return $this->redirect('/admin/team/' . (int) $member['id']);
        }
    }

    private function mediaId(): ?int
    {
        $value = $this->request->request->get('portrait_asset_id');
        return $value !== null && $value !== '' ? (int) $value : null;
    }

    private function service(): TeamService
    {
        return new TeamService($this->app->pdo());
    }
}

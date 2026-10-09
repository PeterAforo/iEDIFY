<?php

declare(strict_types=1);

namespace IEdify\Modules\Community\Http;

use IEdify\Core\Http\Controller;
use IEdify\Core\Http\HttpError;
use Symfony\Component\HttpFoundation\Response;

final class CommunityController extends Controller
{
    public function hub(): Response
    {
        $actor = $this->actor();
        if ($actor === null || !$this->app->policy()->allows($actor, 'community.member') && !$this->app->policy()->allows($actor, 'community.moderate')) {
            $cms = $this->cmsPage('/community');
            return $this->render('community/landing.twig', [
                'page' => $cms['page'] ?? ['title' => 'Community'],
                'sections' => $cms['sections'] ?? [],
                'media' => ($cms['media'] ?? []) + $this->mediaByIds([9]),
                'info' => $cms !== null ? \IEdify\Modules\Web\Services\PageLayouts::build('/community', $cms['sections']) : null,
            ]);
        }
        $community = $this->app->community();
        $profile = $this->app->pdo()->prepare('SELECT * FROM member_profiles WHERE user_id = ?');
        $profile->execute([$actor->id]);
        $bookmarks = $this->app->pdo()->prepare("SELECT p.id, p.title, p.group_id FROM bookmarks b JOIN community_posts p ON p.id = b.post_id WHERE b.user_id = ? AND p.status = 'visible' ORDER BY b.created_at DESC LIMIT 50");
        $bookmarks->execute([$actor->id]);
        return $this->render('community/hub.twig', [
            'groups' => $community->groupsFor($actor),
            'profile' => $profile->fetch() ?: null,
            'bookmarks' => $bookmarks->fetchAll(),
        ]);
    }

    public function directory(): Response
    {
        $query = trim((string) $this->request->query->get('q', ''));
        return $this->render('community/directory.twig', [
            'members' => $this->app->community()->discoverableMembers($query),
            'query' => $query,
        ]);
    }

    public function saveProfile(): Response
    {
        try {
            $this->app->community()->upsertProfile($this->requireActor(), $this->input('display_name'), $this->input('bio') ?: null, $this->input('sector') ?: null, $this->input('location') ?: null, $this->has('discoverable'));
            $this->flash('success', 'Community profile saved.');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/community');
    }

    public function createGroup(): Response
    {
        try {
            $id = $this->app->community()->createGroup($this->requireActor(), $this->input('slug'), $this->input('name'), $this->input('description') ?: null, $this->input('sector') ?: null, $this->input('visibility'));
            $this->flash('success', 'Group created.');
            return $this->redirect('/community/groups/' . $id);
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
            return $this->redirect('/community');
        }
    }

    public function group(): Response
    {
        $id = (int) $this->vars['id'];
        $actor = $this->requireActor();
        $pdo = $this->app->pdo();
        $group = $pdo->prepare('SELECT * FROM community_groups WHERE id = ?');
        $group->execute([$id]);
        $record = $group->fetch();
        if ($record === false) {
            throw new HttpError(404, 'Group was not found.');
        }
        $membership = $pdo->prepare('SELECT status, role FROM group_members WHERE group_id = ? AND user_id = ?');
        $membership->execute([$id, $actor->id]);
        $member = $membership->fetch() ?: null;
        $pending = [];
        if ($member !== null && $member['role'] === 'moderator' && $member['status'] === 'active' || $this->app->policy()->allows($actor, 'community.moderate')) {
            $pendingStatement = $pdo->prepare("SELECT gm.user_id, u.name FROM group_members gm JOIN users u ON u.id = gm.user_id WHERE gm.group_id = ? AND gm.status = 'pending'");
            $pendingStatement->execute([$id]);
            $pending = $pendingStatement->fetchAll();
        }
        return $this->render('community/group.twig', [
            'group' => $record,
            'membership' => $member,
            'posts' => $this->app->community()->postsIn($actor, $id),
            'pending' => $pending,
        ]);
    }

    public function join(): Response
    {
        try {
            $status = $this->app->community()->joinGroup($this->requireActor(), (int) $this->vars['id']);
            $this->flash('success', $status === 'active' ? 'You joined the group.' : 'Membership requested — a moderator will review it.');
        } catch (HttpError $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/community/groups/' . (int) $this->vars['id']);
    }

    public function decideMember(): Response
    {
        try {
            $this->app->community()->approveMember($this->requireActor(), (int) $this->vars['id'], (int) $this->vars['userId'], $this->input('decision') === 'approve');
            $this->flash('success', 'Membership decided.');
        } catch (HttpError $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/community/groups/' . (int) $this->vars['id']);
    }

    public function post(): Response
    {
        $id = (int) $this->vars['id'];
        try {
            $postId = $this->app->community()->createPost($this->requireActor(), $id, $this->input('title'), $this->input('body'));
            $this->flash('success', 'Post created.');
            return $this->redirect('/community/posts/' . $postId);
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
            return $this->redirect('/community/groups/' . $id);
        }
    }

    public function postDetail(): Response
    {
        $actor = $this->requireActor();
        $post = $this->app->community()->post($actor, (int) $this->vars['id']);
        $membership = $this->app->pdo()->prepare('SELECT status FROM group_members WHERE group_id = ? AND user_id = ?');
        $membership->execute([(int) $post['group_id'], $actor->id]);
        $member = $membership->fetch();
        return $this->render('community/post.twig', [
            'post' => $post,
            'can_comment' => $member !== false && $member['status'] === 'active',
        ]);
    }

    public function comment(): Response
    {
        $id = (int) $this->vars['id'];
        try {
            $this->app->community()->createComment($this->requireActor(), $id, $this->input('body'));
            $this->flash('success', 'Comment added.');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/community/posts/' . $id);
    }

    public function bookmark(): Response
    {
        $id = (int) $this->vars['id'];
        try {
            $this->app->community()->bookmark($this->requireActor(), $id, $this->input('remove') !== '1');
        } catch (HttpError $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/community/posts/' . $id);
    }

    public function reportPost(): Response
    {
        return $this->reportTarget('post', (int) $this->vars['id'], '/community/posts/');
    }

    public function reportComment(): Response
    {
        $id = (int) $this->vars['id'];
        $postId = $this->app->pdo()->prepare('SELECT post_id FROM community_comments WHERE id = ?');
        $postId->execute([$id]);
        return $this->reportTarget('comment', $id, '/community/posts/' . (int) $postId->fetchColumn());
    }

    private function reportTarget(string $type, int $targetId, string $redirect): Response
    {
        try {
            $this->app->community()->report($this->requireActor(), $type, $targetId, $this->input('reason'));
            $this->flash('success', 'Report submitted to moderators.');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect($redirect);
    }
}

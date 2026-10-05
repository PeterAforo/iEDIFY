<?php

declare(strict_types=1);

namespace IEdify\Modules\Identity\Services;

use IEdify\Core\Audit\AuditLog;
use IEdify\Core\Database\Transaction;
use IEdify\Core\Http\HttpError;
use IEdify\Core\Jobs\Outbox;
use IEdify\Core\Security\Actor;
use IEdify\Core\Security\Policy;
use IEdify\Core\Security\SecretBox;
use PDO;

/**
 * Staff/partner invitations: expiring single-use links that create an account
 * with a pre-assigned role. Tokens are hashed at rest and only ever sent
 * inside the queued invitation email.
 */
final readonly class InvitationService
{
    private const TTL_SECONDS = 604800; // 7 days

    public function __construct(private PDO $pdo, private SecretBox $secrets)
    {
    }

    /** @return string The raw token (also emailed via the outbox). */
    public function invite(Actor $actor, string $email, int $roleId): string
    {
        if (!(new Policy())->allows($actor, 'identity.manage')) {
            throw new HttpError(403, 'You do not have permission to invite users.');
        }
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('A valid email address is required.');
        }
        $role = $this->pdo->prepare('SELECT id, name FROM roles WHERE id = ?');
        $role->execute([$roleId]);
        if ($role->fetch() === false) {
            throw new \InvalidArgumentException('Unknown role.');
        }
        $existing = $this->pdo->prepare('SELECT id FROM users WHERE email = ?');
        $existing->execute([$email]);
        if ($existing->fetchColumn() !== false) {
            throw new \InvalidArgumentException('An account already exists for that email.');
        }
        $token = bin2hex(random_bytes(32));
        $id = (new Transaction($this->pdo))->run(function () use ($actor, $email, $roleId, $token): int {
            $this->pdo->prepare('INSERT INTO invitations (email, role_id, token_hash, invited_by, expires_at, created_at) VALUES (?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND), UTC_TIMESTAMP(6))')
                ->execute([$email, $roleId, hash('sha256', $token), $actor->id, self::TTL_SECONDS]);
            $id = (int) $this->pdo->lastInsertId();
            (new Outbox($this->pdo))->record('invite.' . $id, 'identity.invite', ['invitation_id' => $id, 'token_ciphertext' => $this->secrets->encrypt($token)]);
            (new AuditLog($this->pdo))->record($actor->id, 'identity.invited', 'invitations', (string) $id);
            return $id;
        });
        unset($id);
        return $token;
    }

    public function pending(): array
    {
        return $this->pdo->query("SELECT i.id, i.email, i.expires_at, i.created_at, r.name AS role_name, u.name AS invited_by_name FROM invitations i JOIN roles r ON r.id = i.role_id LEFT JOIN users u ON u.id = i.invited_by WHERE i.accepted_at IS NULL ORDER BY i.id DESC LIMIT 200")->fetchAll();
    }

    public function preview(string $token): array
    {
        $statement = $this->pdo->prepare('SELECT i.*, r.name AS role_name FROM invitations i JOIN roles r ON r.id = i.role_id WHERE i.token_hash = ?');
        $statement->execute([hash('sha256', $token)]);
        $invite = $statement->fetch();
        if ($invite === false || $invite['accepted_at'] !== null || strtotime((string) $invite['expires_at']) < time()) {
            throw new HttpError(404, 'This invitation is invalid or has expired.');
        }
        return $invite;
    }

    /** Create the invited account with its pre-assigned role; returns the user id. */
    public function accept(string $token, string $name, string $password): int
    {
        $invite = $this->preview($token);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 180) {
            throw new \InvalidArgumentException('A name is required.');
        }
        if (strlen($password) < 12) {
            throw new \InvalidArgumentException('Choose a password of at least 12 characters.');
        }
        return (new Transaction($this->pdo))->run(function () use ($invite, $name, $password): int {
            $locked = $this->pdo->prepare('SELECT id FROM invitations WHERE id = ? AND accepted_at IS NULL FOR UPDATE');
            $locked->execute([$invite['id']]);
            if ($locked->fetchColumn() === false) {
                throw new HttpError(409, 'This invitation was already used.');
            }
            $this->pdo->prepare('INSERT INTO users (public_id, email, name, password_hash, email_verified_at, created_at, updated_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))')
                ->execute([bin2hex(random_bytes(16)), $invite['email'], $name, password_hash($password, PASSWORD_DEFAULT)]);
            $userId = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')->execute([$userId, $invite['role_id']]);
            $this->pdo->prepare('UPDATE invitations SET accepted_at = UTC_TIMESTAMP(6) WHERE id = ?')->execute([$invite['id']]);
            (new AuditLog($this->pdo))->record($userId, 'identity.invite_accepted', 'invitations', (string) $invite['id']);
            return $userId;
        });
    }
}

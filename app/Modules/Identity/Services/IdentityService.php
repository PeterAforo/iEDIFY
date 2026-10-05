<?php

declare(strict_types=1);

namespace IEdify\Modules\Identity\Services;

use IEdify\Core\Audit\AuditLog;
use IEdify\Core\Database\Transaction;
use IEdify\Core\Jobs\Outbox;
use IEdify\Core\Security\Actor;
use IEdify\Core\Security\SecretBox;
use InvalidArgumentException;
use PDO;
use PDOException;

final readonly class IdentityService
{
    public function __construct(private PDO $pdo, private SecretBox $secrets)
    {
    }

    public function register(string $name, string $email, string $password, string $policyVersion, bool $ageAttested): ?int
    {
        $name = trim($name);
        $email = strtolower(trim($email));
        if ($name === '' || mb_strlen($name) > 180 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 || !$ageAttested || $policyVersion === '' || strlen($policyVersion) > 80) {
            throw new InvalidArgumentException('Provide a valid name, email and required policy/age acknowledgements.');
        }
        $this->validatePassword($password);
        $hash = password_hash($password, PASSWORD_DEFAULT);
        try {
            return (new Transaction($this->pdo))->run(function () use ($name, $email, $hash, $policyVersion): int {
                $statement = $this->pdo->prepare('INSERT INTO users (public_id, email, name, password_hash, created_at, updated_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))');
                $statement->execute([bin2hex(random_bytes(16)), $email, $name, $hash]);
                $id = (int) $this->pdo->lastInsertId();
                $statement = $this->pdo->prepare("INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE name = 'participant'");
                $statement->execute([$id]);
                if ($statement->rowCount() !== 1) {
                    throw new \RuntimeException('Identity roles have not been provisioned.');
                }
                foreach (['terms_privacy_acknowledgement', 'minimum_age_attestation'] as $purpose) {
                    $this->pdo->prepare('INSERT INTO consents (user_id, purpose, policy_version, granted, channel, created_at) VALUES (?, ?, ?, TRUE, ?, UTC_TIMESTAMP(6))')->execute([$id, $purpose, $policyVersion, 'signup']);
                }
                $token = bin2hex(random_bytes(32));
                $this->pdo->prepare("INSERT INTO auth_tokens (user_id, purpose, token_hash, expires_at, created_at) VALUES (?, 'email_verify', ?, DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 24 HOUR), UTC_TIMESTAMP(6))")->execute([$id, hash('sha256', $token)]);
                (new Outbox($this->pdo))->record('identity.verify.' . $id, 'identity.verify_email', ['user_id' => $id, 'token_ciphertext' => $this->secrets->encrypt($token)]);
                (new AuditLog($this->pdo))->record($id, 'identity.registered', 'user', (string) $id);
                return $id;
            });
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) === 1062) {
                return null;
            }
            throw $error;
        }
    }

    public function authenticate(string $email, string $password): ?array
    {
        $fallbackHash = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
        $statement = $this->pdo->prepare('SELECT id, name, email, password_hash, email_verified_at, status, version FROM users WHERE email = ?');
        $statement->execute([strtolower(trim($email))]);
        $user = $statement->fetch();
        $hash = $user === false ? $fallbackHash : $user['password_hash'];
        if (!password_verify($password, $hash) || $user === false || $user['status'] !== 'active') {
            return null;
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND password_hash = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $user['id'], $user['password_hash']]);
        }
        return ['id' => (int) $user['id'], 'name' => $user['name'], 'verified' => $user['email_verified_at'] !== null, 'version' => (int) $user['version']];
    }

    public function verifyEmail(string $token): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return false;
        }
        return (new Transaction($this->pdo))->run(function () use ($token): bool {
            $statement = $this->pdo->prepare("SELECT t.id, t.user_id FROM auth_tokens t JOIN users u ON u.id = t.user_id WHERE t.token_hash = ? AND t.purpose = 'email_verify' AND t.consumed_at IS NULL AND t.expires_at > UTC_TIMESTAMP(6) AND u.status = 'active' FOR UPDATE");
            $statement->execute([hash('sha256', $token)]);
            $record = $statement->fetch();
            if ($record === false) {
                return false;
            }
            $this->pdo->prepare('UPDATE auth_tokens SET consumed_at = UTC_TIMESTAMP(6) WHERE id = ?')->execute([$record['id']]);
            $this->pdo->prepare('UPDATE users SET email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP(6)), updated_at = UTC_TIMESTAMP(6) WHERE id = ?')->execute([$record['user_id']]);
            (new AuditLog($this->pdo))->record((int) $record['user_id'], 'identity.email_verified', 'user', (string) $record['user_id']);
            return true;
        });
    }

    public function actor(int $userId, bool $mfaComplete = false): ?Actor
    {
        $query = $this->pdo->prepare("SELECT id, email_verified_at FROM users WHERE id = ? AND status = 'active'");
        $query->execute([$userId]);
        $user = $query->fetch();
        if ($user === false) {
            return null;
        }
        $query = $this->pdo->prepare('SELECT p.name, r.privileged FROM user_roles ur JOIN roles r ON r.id = ur.role_id LEFT JOIN role_permissions rp ON rp.role_id = r.id LEFT JOIN permissions p ON p.id = rp.permission_id WHERE ur.user_id = ?');
        $query->execute([$userId]);
        $rows = $query->fetchAll();
        $permissions = array_values(array_unique(array_filter(array_column($rows, 'name'), 'is_string')));
        $privileged = array_any($rows, static fn (array $row): bool => (bool) $row['privileged']);
        return new Actor($userId, $permissions, $user['email_verified_at'] !== null, $privileged, $mfaComplete);
    }

    public function validatePassword(string $password): void
    {
        if (mb_strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new InvalidArgumentException('Use a password of at least 12 characters and no more than 72 bytes.');
        }
    }
}

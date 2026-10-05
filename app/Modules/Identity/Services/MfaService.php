<?php

declare(strict_types=1);

namespace IEdify\Modules\Identity\Services;

use IEdify\Core\Audit\AuditLog;
use IEdify\Core\Clock\Clock;
use IEdify\Core\Clock\SystemClock;
use IEdify\Core\Database\Transaction;
use IEdify\Core\Http\HttpError;
use IEdify\Core\Security\SecretBox;
use OTPHP\TOTP;
use PDO;

final readonly class MfaService
{
    public function __construct(private PDO $pdo, private SecretBox $secrets, private Clock $clock = new SystemClock())
    {
    }

    public function begin(int $userId, string $password): array
    {
        $query = $this->pdo->prepare('SELECT email FROM users WHERE id = ?');
        $query->execute([$userId]);
        $email = $query->fetchColumn();
        if (!is_string($email) || (new IdentityService($this->pdo, $this->secrets))->authenticate($email, $password) === null) {
            throw new HttpError(403, 'Re-enter your current password to configure multi-factor authentication.');
        }
        $totp = TOTP::generate($this->clock);
        $totp->setIssuer('iEDIFY Africa');
        $totp->setLabel($email);
        (new Transaction($this->pdo))->run(function () use ($userId, $totp): void {
            $lock = $this->pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
            $lock->execute([$userId]);
            $factor = $this->factor($userId);
            if ($factor !== null && $factor['confirmed_at'] !== null) {
                throw new HttpError(409, 'Multi-factor authentication is already configured. Use the controlled recovery process to change it.');
            }
            $ciphertext = $this->secrets->encrypt($totp->getSecret());
            $query = $this->pdo->prepare('INSERT INTO mfa_factors (user_id, secret_ciphertext) VALUES (?, ?) ON DUPLICATE KEY UPDATE secret_ciphertext = ?');
            $query->execute([$userId, $ciphertext, $ciphertext]);
            (new AuditLog($this->pdo))->record($userId, 'identity.mfa_setup_started', 'user', (string) $userId);
        });
        return ['secret' => $totp->getSecret(), 'uri' => $totp->getProvisioningUri()];
    }

    public function confirm(int $userId, string $code): array
    {
        return (new Transaction($this->pdo))->run(function () use ($userId, $code): array {
            $factor = $this->factor($userId, true);
            if ($factor === null || $factor['confirmed_at'] !== null || !$this->validCode($factor, $code)) {
                throw new HttpError(422, 'The authentication code is invalid or enrollment is already complete.');
            }
            $this->pdo->prepare('UPDATE mfa_factors SET confirmed_at = UTC_TIMESTAMP(6), last_used_step = ? WHERE user_id = ?')->execute([$this->step(), $userId]);
            $codes = [];
            for ($index = 0; $index < 8; $index++) {
                $code = strtoupper(bin2hex(random_bytes(16)));
                $codes[] = implode('-', str_split($code, 8));
                $this->pdo->prepare('INSERT INTO mfa_recovery_codes (user_id, code_hash) VALUES (?, ?)')->execute([$userId, hash('sha256', $code)]);
            }
            (new AuditLog($this->pdo))->record($userId, 'identity.mfa_enabled', 'user', (string) $userId);
            return $codes;
        });
    }

    public function verify(int $userId, string $code): bool
    {
        return (new Transaction($this->pdo))->run(function () use ($userId, $code): bool {
            $factor = $this->factor($userId, true);
            if ($factor === null || $factor['confirmed_at'] === null || ($factor['last_used_step'] !== null && (int) $factor['last_used_step'] >= $this->step()) || !$this->validCode($factor, $code)) {
                return false;
            }
            $this->pdo->prepare('UPDATE mfa_factors SET last_used_step = ? WHERE user_id = ?')->execute([$this->step(), $userId]);
            (new AuditLog($this->pdo))->record($userId, 'identity.mfa_verified', 'user', (string) $userId);
            return true;
        });
    }

    public function recover(int $userId, string $code): bool
    {
        $code = str_replace('-', '', strtoupper(trim($code)));
        if (!preg_match('/^[A-F0-9]{32}$/D', $code)) {
            return false;
        }
        return (new Transaction($this->pdo))->run(function () use ($userId, $code): bool {
            $factor = $this->factor($userId, true);
            if ($factor === null || $factor['confirmed_at'] === null) {
                return false;
            }
            $query = $this->pdo->prepare('SELECT id FROM mfa_recovery_codes WHERE user_id = ? AND code_hash = ? AND used_at IS NULL FOR UPDATE');
            $query->execute([$userId, hash('sha256', $code)]);
            $id = $query->fetchColumn();
            if ($id === false) {
                return false;
            }
            $this->pdo->prepare('UPDATE mfa_recovery_codes SET used_at = UTC_TIMESTAMP(6) WHERE id = ?')->execute([$id]);
            (new AuditLog($this->pdo))->record($userId, 'identity.mfa_recovery_used', 'user', (string) $userId);
            return true;
        });
    }

    /**
     * Provisioning URI for an enrollment that was started but not yet confirmed.
     */
    public function pendingSetup(int $userId): ?string
    {
        $factor = $this->factor($userId);
        if ($factor === null || $factor['confirmed_at'] !== null) {
            return null;
        }
        $query = $this->pdo->prepare('SELECT email FROM users WHERE id = ?');
        $query->execute([$userId]);
        $email = $query->fetchColumn();
        if (!is_string($email)) {
            return null;
        }
        $totp = TOTP::createFromSecret($this->secrets->decrypt($factor['secret_ciphertext']), $this->clock);
        $totp->setIssuer('iEDIFY Africa');
        $totp->setLabel($email);
        return $totp->getProvisioningUri();
    }

    public function enabled(int $userId): bool
    {
        $factor = $this->factor($userId);
        return $factor !== null && $factor['confirmed_at'] !== null;
    }

    private function factor(int $userId, bool $lock = false): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM mfa_factors WHERE user_id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $query->execute([$userId]);
        $row = $query->fetch();
        return $row === false ? null : $row;
    }

    private function validCode(array $factor, string $code): bool
    {
        return preg_match('/^[0-9]{6}$/D', $code) === 1
            && TOTP::createFromSecret($this->secrets->decrypt($factor['secret_ciphertext']), $this->clock)->verify($code, $this->clock->now()->getTimestamp());
    }

    private function step(): int
    {
        return intdiv($this->clock->now()->getTimestamp(), 30);
    }
}

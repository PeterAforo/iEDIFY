<?php

declare(strict_types=1);

namespace IEdify\Core;

use IEdify\Core\Clock\Clock;
use IEdify\Core\Clock\SystemClock;
use IEdify\Core\Database\Connection;
use IEdify\Core\Security\Actor;
use IEdify\Core\Security\Csrf;
use IEdify\Core\Security\Policy;
use IEdify\Core\Security\RateLimiter;
use IEdify\Core\Security\SecretBox;
use IEdify\Core\View\View;
use IEdify\Modules\CMS\Services\CmsService;
use IEdify\Modules\Identity\Services\IdentityService;
use IEdify\Modules\Identity\Services\MfaService;
use PDO;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Request-scoped service access. Database connections are opened lazily so
 * health checks and error responses never require MySQL.
 */
final class Application
{
    public const SESSION_USER = 'auth.user_id';
    public const SESSION_VERSION = 'auth.user_version';
    public const SESSION_MFA = 'auth.mfa_complete';
    public const SESSION_PENDING_MFA = 'auth.pending_mfa_user_id';
    public const SESSION_INTENDED = 'auth.intended_path';

    private ?PDO $pdo = null;
    private ?Actor $actor = null;
    private bool $actorResolved = false;

    public function __construct(
        public readonly Config $config,
        public readonly View $view,
        public readonly SessionInterface $session,
        public readonly LoggerInterface $logger,
        public readonly string $root,
    ) {
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= Connection::open($this->config);
    }

    public function secrets(): SecretBox
    {
        return new SecretBox($this->config->string('APP_KEY'));
    }

    public function clock(): Clock
    {
        return new SystemClock();
    }

    public function csrf(): Csrf
    {
        return new Csrf($this->session);
    }

    public function rateLimiter(): RateLimiter
    {
        return new RateLimiter($this->pdo(), $this->clock(), $this->config->string('APP_KEY'));
    }

    public function identity(): IdentityService
    {
        return new IdentityService($this->pdo(), $this->secrets());
    }

    public function mfa(): MfaService
    {
        return new MfaService($this->pdo(), $this->secrets(), $this->clock());
    }

    public function cms(): CmsService
    {
        return new CmsService($this->pdo());
    }

    public function programs(): \IEdify\Modules\Programs\Services\ProgramService
    {
        return new \IEdify\Modules\Programs\Services\ProgramService($this->pdo());
    }

    public function applications(): \IEdify\Modules\Programs\Services\ApplicationService
    {
        return new \IEdify\Modules\Programs\Services\ApplicationService($this->pdo());
    }

    public function learning(): \IEdify\Modules\Learning\Services\LearningService
    {
        return new \IEdify\Modules\Learning\Services\LearningService($this->pdo());
    }

    public function mentoring(): \IEdify\Modules\Learning\Services\MentorshipService
    {
        return new \IEdify\Modules\Learning\Services\MentorshipService($this->pdo());
    }

    public function milestones(): \IEdify\Modules\Learning\Services\MilestoneService
    {
        return new \IEdify\Modules\Learning\Services\MilestoneService($this->pdo());
    }

    public function events(): \IEdify\Modules\Community\Services\EventService
    {
        return new \IEdify\Modules\Community\Services\EventService($this->pdo());
    }

    public function community(): \IEdify\Modules\Community\Services\CommunityService
    {
        return new \IEdify\Modules\Community\Services\CommunityService($this->pdo());
    }

    public function funding(): \IEdify\Modules\Funding\Services\FundingService
    {
        return new \IEdify\Modules\Funding\Services\FundingService($this->pdo());
    }

    public function disbursements(): \IEdify\Modules\Funding\Services\DisbursementService
    {
        return new \IEdify\Modules\Funding\Services\DisbursementService($this->pdo());
    }

    public function impact(): \IEdify\Modules\Impact\Services\ImpactService
    {
        return new \IEdify\Modules\Impact\Services\ImpactService($this->pdo());
    }

    public function partners(): \IEdify\Modules\Partners\Services\PartnerService
    {
        return new \IEdify\Modules\Partners\Services\PartnerService($this->pdo());
    }

    public function policy(): Policy
    {
        return new Policy();
    }

    public function flashes(): \Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface
    {
        $bag = $this->session->getBag('flashes');
        if (!$bag instanceof \Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface) {
            $bag = new \Symfony\Component\HttpFoundation\Session\Flash\AutoExpireFlashBag();
            $this->session->registerBag($bag);
        }
        return $bag;
    }

    /**
     * Resolve the signed-in actor. Sessions pin the user version recorded at
     * sign-in; a credential or privilege change bumps the version and ends
     * every other session automatically.
     */
    public function actor(): ?Actor
    {
        if ($this->actorResolved) {
            return $this->actor;
        }
        $this->actorResolved = true;
        $userId = $this->session->get(self::SESSION_USER);
        if (!is_int($userId)) {
            return null;
        }
        $actor = $this->identity()->actor($userId, $this->session->get(self::SESSION_MFA) === true);
        if ($actor === null || $this->identity()->currentVersion($userId) !== $this->session->get(self::SESSION_VERSION)) {
            $this->clearAuthentication();
            return null;
        }
        return $this->actor = $actor;
    }

    public function grantSession(int $userId, bool $mfaComplete): void
    {
        $this->session->migrate(true);
        $this->session->set(self::SESSION_USER, $userId);
        $this->session->set(self::SESSION_VERSION, $this->identity()->currentVersion($userId));
        $this->session->set(self::SESSION_MFA, $mfaComplete);
        $this->session->remove(self::SESSION_PENDING_MFA);
        $this->actor = null;
        $this->actorResolved = false;
    }

    public function clearAuthentication(): void
    {
        foreach ([self::SESSION_USER, self::SESSION_VERSION, self::SESSION_MFA, self::SESSION_PENDING_MFA, self::SESSION_INTENDED] as $key) {
            $this->session->remove($key);
        }
        $this->actor = null;
        $this->actorResolved = true;
    }
}

<?php

declare(strict_types=1);

namespace IEdify\Core\Security;

final class Policy
{
    public function allows(?Actor $actor, string $permission): bool
    {
        return $actor !== null
            && $actor->verified
            && (!$actor->privileged || $actor->mfaComplete)
            && in_array($permission, $actor->permissions, true);
    }

    public function owns(?Actor $actor, int $ownerId, string $permission): bool
    {
        return $this->allows($actor, $permission) && $actor?->id === $ownerId;
    }
}

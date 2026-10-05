<?php

declare(strict_types=1);

namespace IEdify\Core\Security;

final readonly class Actor
{
    public function __construct(
        public int $id,
        public array $permissions,
        public bool $verified,
        public bool $privileged,
        public bool $mfaComplete,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use DomainException;

final class BusinessAccessPolicy
{
    public function assertCanAccess(int $actorBusinessId, int $resourceBusinessId): void
    {
        if ($actorBusinessId < 1 || $resourceBusinessId < 1 || $actorBusinessId !== $resourceBusinessId) {
            throw new DomainException('Access to this business resource is denied.');
        }
    }

    public function canAccess(int $actorBusinessId, int $resourceBusinessId): bool
    {
        return $actorBusinessId > 0
            && $resourceBusinessId > 0
            && $actorBusinessId === $resourceBusinessId;
    }
}

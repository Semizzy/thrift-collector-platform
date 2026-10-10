<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Tenancy\BusinessAccessPolicy;
use DomainException;
use PHPUnit\Framework\TestCase;

final class BusinessAccessPolicyTest extends TestCase
{
    public function test_same_business_can_access_its_resource(): void
    {
        self::assertTrue((new BusinessAccessPolicy())->canAccess(12, 12));
    }

    public function test_cross_business_access_is_denied(): void
    {
        $this->expectException(DomainException::class);
        (new BusinessAccessPolicy())->assertCanAccess(12, 13);
    }

    public function test_invalid_business_ids_fail_closed(): void
    {
        self::assertFalse((new BusinessAccessPolicy())->canAccess(0, 12));
        self::assertFalse((new BusinessAccessPolicy())->canAccess(12, 0));
    }
}

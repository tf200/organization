<?php

declare(strict_types=1);

namespace OCA\Organization\Tests\Unit\Service;

use OCP\AppFramework\OCS\OCSException;

use OCA\Organization\Service\PlanEntitlementValidator;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PlanEntitlementValidatorTest extends TestCase
{
    public function testAcceptsPositiveEntitlements(): void
    {
        (new PlanEntitlementValidator())->validate(1, 1, 1, 1);

        $this->addToAssertionCount(1);
    }

    /**
     * @param array{0:?int,1:?int,2:?int,3:?int} $entitlements
     */
    #[DataProvider('invalidEntitlementsProvider')]
    public function testRejectsMissingOrNonPositiveEntitlements(array $entitlements): void
    {
        $this->expectException(OCSException::class);

        (new PlanEntitlementValidator())->validate(...$entitlements);
    }

    /**
     * @return array<string,array{array{0:?int,1:?int,2:?int,3:?int}}>
     */
    public static function invalidEntitlementsProvider(): array
    {
        return [
            'missing members' => [[null, 1, 1, 1]],
            'zero members' => [[0, 1, 1, 1]],
            'zero projects' => [[1, 0, 1, 1]],
            'zero shared storage' => [[1, 1, 0, 1]],
            'zero private storage' => [[1, 1, 1, 0]],
        ];
    }
}

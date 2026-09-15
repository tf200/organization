<?php

declare(strict_types=1);

namespace OCA\Organization\Tests\Unit\Db;

use OCA\Organization\Db\Team;
use PHPUnit\Framework\TestCase;

class TeamTest extends TestCase
{
    public function testProjectCapacityIsComputedFromFteAndProjectsPerFte(): void
    {
        $team = new Team();
        $team->setFte(2.5);
        $team->setProjectsPerFte(4.0);

        $payload = $team->jsonSerialize();

        self::assertSame(10.0, $payload['projectCapacity']);
    }

    public function testProjectCapacityIsRoundedToTwoDecimalPlaces(): void
    {
        $team = new Team();
        $team->setFte(1.11);
        $team->setProjectsPerFte(1.11);

        self::assertSame(1.23, $team->jsonSerialize()['projectCapacity']);
    }
}

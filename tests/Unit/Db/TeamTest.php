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

    public function testDateTimeStringsAreConvertedAndSerialized(): void
    {
        $team = new Team();
        $team->setCreatedAt('2026-09-15 10:20:30');
        $team->setUpdatedAt('2026-09-16 11:21:31');

        $payload = $team->jsonSerialize();

        self::assertInstanceOf(\DateTime::class, $team->createdAt);
        self::assertInstanceOf(\DateTime::class, $team->updatedAt);
        self::assertSame('2026-09-15 10:20:30', $payload['createdAt']);
        self::assertSame('2026-09-16 11:21:31', $payload['updatedAt']);
    }
}

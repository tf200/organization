<?php

declare(strict_types=1);

namespace OCA\Organization\Tests\Unit\Service;

use OCA\Organization\Service\StorageThresholdService;

use PHPUnit\Framework\TestCase;

class StorageThresholdServiceTest extends TestCase
{
    private StorageThresholdService $service;

    protected function setUp(): void
    {
        $this->service = (new \ReflectionClass(StorageThresholdService::class))->newInstanceWithoutConstructor();
    }

    public function testDeterminesThresholdLevels(): void
    {
        self::assertSame(0, $this->service->determineThreshold(799, 1000));
        self::assertSame(80, $this->service->determineThreshold(800, 1000));
        self::assertSame(80, $this->service->determineThreshold(999, 1000));
        self::assertSame(100, $this->service->determineThreshold(1000, 1000));
        self::assertSame(100, $this->service->determineThreshold(1200, 1000));
        self::assertSame(0, $this->service->determineThreshold(1200, 0));
    }

    public function testNotifiesOnlyWhenCrossingUpward(): void
    {
        self::assertSame(80, $this->service->determineNotificationThreshold(0, 80));
        self::assertSame(100, $this->service->determineNotificationThreshold(0, 100));
        self::assertSame(100, $this->service->determineNotificationThreshold(80, 100));
        self::assertNull($this->service->determineNotificationThreshold(80, 80));
        self::assertNull($this->service->determineNotificationThreshold(100, 80));
        self::assertNull($this->service->determineNotificationThreshold(80, 0));
    }
}

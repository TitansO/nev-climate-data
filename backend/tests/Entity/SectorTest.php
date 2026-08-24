<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Sector;
use PHPUnit\Framework\TestCase;

final class SectorTest extends TestCase
{
    public function testConstructorSetsFields(): void
    {
        $sector = new Sector('Renewable Energy');

        self::assertNull($sector->getId());
        self::assertSame('Renewable Energy', $sector->getName());
    }

    public function testSetterUpdatesField(): void
    {
        $sector = new Sector('Renewable Energy');

        $sector->setName('Sustainable Transport');

        self::assertSame('Sustainable Transport', $sector->getName());
    }
}

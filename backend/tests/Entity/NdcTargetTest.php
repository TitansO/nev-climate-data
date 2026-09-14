<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Country;
use App\Entity\NdcTarget;
use App\Entity\Sector;
use PHPUnit\Framework\TestCase;

final class NdcTargetTest extends TestCase
{
    public function testConstructorDefaultsToConditionalWithNoSectorOrAmount(): void
    {
        $ndcTarget = new NdcTarget(new Country('Nigeria', 'NGA', "Afrique de l'Ouest"), 2030);

        self::assertNull($ndcTarget->getId());
        self::assertSame('Nigeria', $ndcTarget->getCountry()->getName());
        self::assertNull($ndcTarget->getSector());
        self::assertNull($ndcTarget->getReductionTargetPercent());
        self::assertSame(2030, $ndcTarget->getTargetYear());
        self::assertNull($ndcTarget->getDeclaredNeedUsd());
        self::assertTrue($ndcTarget->isConditional());
    }

    public function testFieldsCanBeSet(): void
    {
        $ndcTarget = new NdcTarget(new Country('Nigeria', 'NGA', "Afrique de l'Ouest"), 2030);

        $ndcTarget->setSector(new Sector('Renewable Energy'));
        $ndcTarget->setReductionTargetPercent(45.0);
        $ndcTarget->setDeclaredNeedUsd('177000000000.00');
        $ndcTarget->setConditional(false);

        self::assertSame('Renewable Energy', $ndcTarget->getSector()->getName());
        self::assertSame(45.0, $ndcTarget->getReductionTargetPercent());
        self::assertSame('177000000000.00', $ndcTarget->getDeclaredNeedUsd());
        self::assertFalse($ndcTarget->isConditional());
    }
}

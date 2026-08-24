<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Enum\SourceReliability;
use App\Entity\Enum\SourceType;
use App\Entity\Source;
use PHPUnit\Framework\TestCase;

final class SourceTest extends TestCase
{
    public function testConstructorSetsFields(): void
    {
        $source = new Source('Internal Demo', SourceType::InternalDemo, SourceReliability::Medium);

        self::assertNull($source->getId());
        self::assertSame('Internal Demo', $source->getName());
        self::assertSame(SourceType::InternalDemo, $source->getType());
        self::assertSame(SourceReliability::Medium, $source->getReliability());
    }

    public function testSettersUpdateFields(): void
    {
        $source = new Source('Internal Demo', SourceType::InternalDemo, SourceReliability::Medium);

        $source->setName('World Bank');
        $source->setType(SourceType::OfficialApi);
        $source->setReliability(SourceReliability::High);

        self::assertSame('World Bank', $source->getName());
        self::assertSame(SourceType::OfficialApi, $source->getType());
        self::assertSame(SourceReliability::High, $source->getReliability());
    }
}

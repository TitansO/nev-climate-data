<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Country;
use App\Entity\Enum\FundingType;
use App\Entity\Enum\SourceReliability;
use App\Entity\Enum\SourceType;
use App\Entity\Enum\ValidationStatus;
use App\Entity\Funding;
use App\Entity\Sector;
use App\Entity\Source;
use PHPUnit\Framework\TestCase;

final class FundingTest extends TestCase
{
    private function makeFunding(): Funding
    {
        return new Funding(
            new Country('Senegal', 'SEN', 'West Africa'),
            new Sector('Renewable Energy'),
            2025,
            '1000000.00',
            FundingType::Public,
            new Source('Internal Demo', SourceType::InternalDemo, SourceReliability::Medium),
            new \DateTimeImmutable('2026-08-20'),
            ValidationStatus::Demo,
        );
    }

    public function testConstructorSetsFields(): void
    {
        $funding = $this->makeFunding();

        self::assertNull($funding->getId());
        self::assertSame('Senegal', $funding->getCountry()->getName());
        self::assertSame('Renewable Energy', $funding->getSector()->getName());
        self::assertSame(2025, $funding->getYear());
        self::assertSame('1000000.00', $funding->getAmount());
        self::assertNull($funding->getOriginalAmount());
        self::assertNull($funding->getOriginalCurrency());
        self::assertNull($funding->getExchangeRate());
        self::assertSame(FundingType::Public, $funding->getFundingType());
        self::assertSame('Internal Demo', $funding->getSource()->getName());
        self::assertSame('2026-08-20', $funding->getCollectionDate()->format('Y-m-d'));
        self::assertSame(ValidationStatus::Demo, $funding->getValidationStatus());
        self::assertNull($funding->getValidFrom());
        self::assertNull($funding->getValidTo());
        self::assertTrue($funding->isCurrent());
    }

    public function testOriginalCurrencyFieldsCanBeSet(): void
    {
        $funding = $this->makeFunding();

        $funding->setOriginalAmount('850000.00');
        $funding->setOriginalCurrency('EUR');
        $funding->setExchangeRate('1.176471');

        self::assertSame('850000.00', $funding->getOriginalAmount());
        self::assertSame('EUR', $funding->getOriginalCurrency());
        self::assertSame('1.176471', $funding->getExchangeRate());
    }

    public function testHistorizationFieldsCanBeSet(): void
    {
        $funding = $this->makeFunding();
        $validFrom = new \DateTimeImmutable('2026-08-20');
        $validTo = new \DateTimeImmutable('2026-09-01');

        $funding->setValidFrom($validFrom);
        $funding->setValidTo($validTo);
        $funding->setIsCurrent(false);

        self::assertSame($validFrom, $funding->getValidFrom());
        self::assertSame($validTo, $funding->getValidTo());
        self::assertFalse($funding->isCurrent());
    }
}

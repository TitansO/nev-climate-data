<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Country;
use PHPUnit\Framework\TestCase;

final class CountryTest extends TestCase
{
    public function testConstructorSetsFields(): void
    {
        $country = new Country('Senegal', 'SEN', 'West Africa', 'XOF');

        self::assertNull($country->getId());
        self::assertSame('Senegal', $country->getName());
        self::assertSame('SEN', $country->getIsoCode());
        self::assertSame('West Africa', $country->getRegion());
        self::assertSame('XOF', $country->getCurrency());
    }

    public function testCurrencyDefaultsToNullWhenOmitted(): void
    {
        $country = new Country('Senegal', 'SEN', 'West Africa');

        self::assertNull($country->getCurrency());
    }

    public function testSettersUpdateFields(): void
    {
        $country = new Country('Senegal', 'SEN', 'West Africa', 'XOF');

        $country->setName('Republic of Senegal');
        $country->setIsoCode('SN');
        $country->setRegion('Sub-Saharan Africa');
        $country->setCurrency('EUR');

        self::assertSame('Republic of Senegal', $country->getName());
        self::assertSame('SN', $country->getIsoCode());
        self::assertSame('Sub-Saharan Africa', $country->getRegion());
        self::assertSame('EUR', $country->getCurrency());
    }
}

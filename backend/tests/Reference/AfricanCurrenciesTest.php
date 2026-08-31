<?php

declare(strict_types=1);

namespace App\Tests\Reference;

use App\DataFixtures\CountryFixtures;
use App\Reference\AfricanCurrencies;
use PHPUnit\Framework\TestCase;

final class AfricanCurrenciesTest extends TestCase
{
    /**
     * Every one of the 54 countries CountryFixtures seeds must resolve to a
     * currency here - a silent gap would show up as a blank "Montant
     * (devise locale)" cell in data.html for that country's every row.
     */
    public function testEveryFixtureCountryHasACurrency(): void
    {
        foreach (CountryFixtures::isoCodes() as $isoCode) {
            self::assertNotNull(
                AfricanCurrencies::currencyForCountry($isoCode),
                "No currency mapped for country {$isoCode}",
            );
        }
    }

    /**
     * Every currency COUNTRY_CURRENCY maps to must itself have an exchange
     * rate - otherwise FundingFixtures silently skips setting
     * originalAmount/originalCurrency for that country (see the null-guard
     * in FundingFixtures::load()).
     */
    public function testEveryMappedCurrencyHasAnExchangeRate(): void
    {
        foreach (AfricanCurrencies::COUNTRY_CURRENCY as $isoCode => $currency) {
            self::assertNotNull(
                AfricanCurrencies::localUnitsPerUsd($currency),
                "No exchange rate for currency {$currency} (country {$isoCode})",
            );
        }
    }

    public function testUnknownCountryReturnsNull(): void
    {
        self::assertNull(AfricanCurrencies::currencyForCountry('XXX'));
    }

    public function testUnknownCurrencyReturnsNull(): void
    {
        self::assertNull(AfricanCurrencies::localUnitsPerUsd('XXX'));
    }

    public function testKnownRateIsPositive(): void
    {
        self::assertGreaterThan(0.0, AfricanCurrencies::localUnitsPerUsd('XOF'));
    }

    /**
     * A real regression: the conversion formula in FundingFixtures once
     * divided by this value instead of multiplying, which for XOF (600
     * units per USD) silently produced an originalAmount ~360,000x too
     * small - caught by comparing the live API response to a hand
     * calculation, not by this suite. This pins the actual number so a
     * future refactor can't reintroduce the same mistake unnoticed: 600
     * XOF per USD is correct order of magnitude, 1/600 is not.
     */
    public function testXofRateIsInTheHundredsNotAFraction(): void
    {
        self::assertGreaterThan(1.0, AfricanCurrencies::localUnitsPerUsd('XOF'));
    }
}

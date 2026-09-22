<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Sector;
use App\Service\BlendedFinanceService;
use PHPUnit\Framework\TestCase;

/**
 * C3.4 : BlendedFinanceService::simulate() - pur calcul paramétrique, pas
 * de dépendance DB, testable en TestCase simple.
 */
final class BlendedFinanceServiceTest extends TestCase
{
    public function testSimulateAppliesSectorBaseLeverageAndInstrumentMix(): void
    {
        $service = new BlendedFinanceService();
        $sector = new Sector('Renewable Energy');

        $result = $service->simulate($sector, 1_000_000.0, ['guarantee' => 0.7, 'grant' => 0.3]);

        // base leverage 3.5 * (0.7*1.4 + 0.3*0.6) = 3.5 * 1.16 = 4.06
        self::assertSame(4.06, $result['leverageRatio']);
        self::assertSame(4_060_000.0, $result['privateAmountMobilizedUsd']);
        self::assertSame(5_060_000.0, $result['totalMobilizedUsd']);
        self::assertCount(3, $result['waterfall']);
        self::assertSame('Total mobilisé', $result['waterfall'][2]['stage']);
        self::assertSame(5_060_000.0, $result['waterfall'][2]['amountUsd']);
    }

    public function testSimulateFallsBackToTheDefaultLeverageForAnUnlistedSector(): void
    {
        $service = new BlendedFinanceService();
        $sector = new Sector('Water Management');

        $result = $service->simulate($sector, 100_000.0, ['grant' => 1.0]);

        // default base leverage 1.5 * grant multiplier 0.6 = 0.9
        self::assertSame(0.9, $result['leverageRatio']);
        self::assertSame(90_000.0, $result['privateAmountMobilizedUsd']);
    }

    public function testSimulateWithoutAnInstrumentMixUsesTheNeutralMultiplier(): void
    {
        $service = new BlendedFinanceService();
        $sector = new Sector('Forestry');

        $result = $service->simulate($sector, 50_000.0, []);

        // base leverage 1.8 * neutral (unknown) multiplier 1.0 = 1.8
        self::assertSame(1.8, $result['leverageRatio']);
        self::assertSame(90_000.0, $result['privateAmountMobilizedUsd']);
    }

    public function testSimulateAnUnknownInstrumentInTheMixFallsBackToTheNeutralMultiplier(): void
    {
        $service = new BlendedFinanceService();
        $sector = new Sector('Agriculture');

        $result = $service->simulate($sector, 10_000.0, ['some_future_instrument' => 1.0]);

        // base leverage 1.5 * neutral (unrecognized instrument) multiplier 1.0 = 1.5
        self::assertSame(1.5, $result['leverageRatio']);
    }
}

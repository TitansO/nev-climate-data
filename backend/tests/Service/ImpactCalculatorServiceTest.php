<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ImpactFactor;
use App\Entity\Sector;
use App\Repository\ImpactFactorRepository;
use App\Service\ImpactCalculatorService;
use PHPUnit\Framework\TestCase;

/**
 * Audit post-C3 2026-10-01 : premier test jamais ecrit pour ce service
 * (couverture a zero constatee lors de l'audit complet du projet), malgre
 * un endpoint public deja en production (POST /api/impact/estimate, C2.5).
 */
final class ImpactCalculatorServiceTest extends TestCase
{
    private function makeService(?ImpactFactor $factor): ImpactCalculatorService
    {
        // createStub(), not createMock() : aucun test ici ne verifie que la
        // methode a ete appelee (->expects()), juste sa valeur de retour -
        // PHPUnit 13 recommande explicitement un stub dans ce cas.
        $repository = $this->createStub(ImpactFactorRepository::class);
        $repository->method('findOneBySectorId')->willReturn($factor);

        return new ImpactCalculatorService($repository);
    }

    public function testReturnsUnavailableWhenNoFactorExistsForTheSector(): void
    {
        $sector = new Sector('Renewable Energy');
        $service = $this->makeService(null);

        $result = $service->estimate($sector, 5_000_000.0);

        self::assertSame(['available' => false], $result);
    }

    public function testEstimatesOnlyTheMetricsTheFactorDefines(): void
    {
        $sector = new Sector('Renewable Energy');
        $factor = new ImpactFactor($sector, 20.0, 'IRENA Renewable Power Generation Costs 2023');
        // Adaptation n'evite pas d'emissions par definition (voir le
        // docblock de l'entite) : usdPerTco2Avoided reste null, les deux
        // autres metriques sont renseignees - l'estimation ne doit
        // contenir QUE les cles correspondant a des facteurs reellement
        // definis, jamais une metrique inventee a zero.
        $factor->setUsdPerMwInstalled('1000000.00');
        $factor->setUsdPerBeneficiary('500.00');

        $service = $this->makeService($factor);
        $result = $service->estimate($sector, 5_000_000.0);

        self::assertTrue($result['available']);
        self::assertSame('Renewable Energy', $result['sector']);
        self::assertSame(5_000_000.0, $result['amountUsd']);
        self::assertArrayNotHasKey('co2AvoidedTonsPerYear', $result['estimates']);
        self::assertArrayHasKey('mwInstalled', $result['estimates']);
        self::assertArrayHasKey('beneficiaries', $result['estimates']);
        self::assertSame(20.0, $result['uncertaintyPercent']);
        self::assertSame('IRENA Renewable Power Generation Costs 2023', $result['methodologySource']);
    }

    public function testComputesTheCentralValueAndASymmetricUncertaintyRange(): void
    {
        $sector = new Sector('Renewable Energy');
        $factor = new ImpactFactor($sector, 10.0, 'Test methodology');
        $factor->setUsdPerMwInstalled('1000000.00');

        $service = $this->makeService($factor);
        $result = $service->estimate($sector, 5_000_000.0);

        // 5,000,000 / 1,000,000 = 5 MW central ; +/- 10% = 4.5 / 5.5.
        self::assertSame(['central' => 5.0, 'low' => 4.5, 'high' => 5.5], $result['estimates']['mwInstalled']);
    }

    public function testNeverReturnsANegativeLowBound(): void
    {
        // Audit post-C3 2026-10-01 : regression test - withRange() borne
        // explicitement `low` a 0.0 (voir son commentaire), ce qui n'etait
        // couvert par aucun test avant celui-ci.
        $sector = new Sector('Renewable Energy');
        $factor = new ImpactFactor($sector, 150.0, 'Test methodology');
        $factor->setUsdPerBeneficiary('1000000.00');

        $service = $this->makeService($factor);
        $result = $service->estimate($sector, 1_000_000.0);

        // 1 beneficiaire central, +/-150% -> low serait negatif sans le clamp.
        self::assertSame(0.0, $result['estimates']['beneficiaries']['low']);
    }
}

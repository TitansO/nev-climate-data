<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\WarmRecommendationsCacheCommand;
use App\Service\RecommendationService;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A3.15 : "app:warm-recommendations-cache" - une fine enveloppe console
 * autour d'App\Service\RecommendationService (déjà testé en détail par
 * RecommendationServiceTest) ; ce test vérifie seulement le branchement
 * commande <-> service et le message affiché, même raisonnement que
 * EvaluateAlertsCommandTest (C3.8) pour son propre service.
 */
final class WarmRecommendationsCacheCommandTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        static::getContainer()->get('cache.analytics')->clear();
    }

    public function testReportsTheCountryCountAndDuration(): void
    {
        $command = new WarmRecommendationsCacheCommand(static::getContainer()->get(RecommendationService::class));
        $application = new Application(self::$kernel);
        $application->addCommand($command);

        $tester = new CommandTester($application->find('app:warm-recommendations-cache'));
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertMatchesRegularExpression('/Cache des recommandations prechauffe : \d+ pays, \d+[.,]\ds\./', $tester->getDisplay());
    }
}

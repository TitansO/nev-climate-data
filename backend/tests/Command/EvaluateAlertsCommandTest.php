<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\EvaluateAlertsCommand;
use App\Entity\AlertRule;
use App\Entity\Country;
use App\Entity\Enum\AlertRuleType;
use App\Entity\Enum\FundingType;
use App\Entity\Enum\SourceReliability;
use App\Entity\Enum\SourceType;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\ValidationStatus;
use App\Entity\Funding;
use App\Entity\NdcTarget;
use App\Entity\Sector;
use App\Entity\Source;
use App\Entity\User;
use App\Service\AlertEvaluationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * C3.8 : "app:alerts:evaluate" - une fine enveloppe console autour
 * d'App\Service\AlertEvaluationService (déjà testé en détail par
 * AlertEvaluationServiceTest) ; ce test vérifie seulement le branchement
 * commande <-> service et le message affiché, pas les 4 conditions.
 */
final class EvaluateAlertsCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        static::getContainer()->get('cache.analytics')->clear();
    }

    protected function tearDown(): void
    {
        $connection = $this->entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        $this->entityManager->close();
        parent::tearDown();
    }

    private function commandTester(): CommandTester
    {
        $command = new EvaluateAlertsCommand(static::getContainer()->get(AlertEvaluationService::class));
        $application = new Application(self::$kernel);
        $application->addCommand($command);

        return new CommandTester($application->find('app:alerts:evaluate'));
    }

    public function testWithNoActiveRuleReportsZeroNotifications(): void
    {
        $tester = $this->commandTester();
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('0 notification', $tester->getDisplay());
    }

    public function testCreatesANotificationForACriticalGapAndReportsTheCount(): void
    {
        $user = new User('Amina Diallo', 'evaluate-alerts@example.com', password_hash('correct-horse-battery-staple', PASSWORD_DEFAULT), UserRole::InternalAnalyst);
        $this->entityManager->persist($user);
        $this->entityManager->persist(new AlertRule($user, AlertRuleType::GapCritical));

        $nigeria = new Country('Nigeria', 'NGA', "Afrique de l'Ouest");
        $sector = new Sector('Renewable Energy');
        $source = new Source('Test Source', SourceType::InternalDemo, SourceReliability::Medium);
        $this->entityManager->persist($nigeria);
        $this->entityManager->persist($sector);
        $this->entityManager->persist($source);
        $target = new NdcTarget($nigeria, 2030);
        $target->setDeclaredNeedUsd('1000.00');
        $this->entityManager->persist($target);
        $this->entityManager->persist(new Funding($nigeria, $sector, 2024, '100.00', FundingType::Public, $source, new \DateTimeImmutable('2024-01-01'), ValidationStatus::Validated));
        $this->entityManager->flush();

        $tester = $this->commandTester();
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('1 notification', $tester->getDisplay());
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AlertRule;
use App\Entity\Country;
use App\Entity\Enum\AlertRuleType;
use App\Entity\Enum\FundingType;
use App\Entity\Enum\ScoreSubjectType;
use App\Entity\Enum\SourceReliability;
use App\Entity\Enum\SourceType;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\ValidationStatus;
use App\Entity\Funding;
use App\Entity\NdcTarget;
use App\Entity\Opportunity;
use App\Entity\Score;
use App\Entity\Sector;
use App\Entity\Source;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Service\AlertEvaluationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * C3.8 : AlertEvaluationService::evaluate() - une condition par test, plus
 * la déduplication (une condition qui reste vraie sur 2 exécutions ne
 * notifie qu'une fois tant que la notification reste non lue).
 */
final class AlertEvaluationServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private static int $userCounter = 0;

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

    private function createUser(): User
    {
        $email = \sprintf('alert-eval-%d@example.com', ++self::$userCounter);
        $user = new User('Amina Diallo', $email, password_hash('correct-horse-battery-staple', PASSWORD_DEFAULT), UserRole::InternalAnalyst);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    public function testEvaluateCreatesNoNotificationsWithoutAnyActiveRule(): void
    {
        $service = static::getContainer()->get(AlertEvaluationService::class);

        self::assertSame(0, $service->evaluate());
    }

    public function testGapCriticalRuleNotifiesItsOwnerForACriticalGap(): void
    {
        $user = $this->createUser();
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
        // 100/1000 = 0.1 coverage, below the 0.25 "critical" ceiling.
        $this->entityManager->persist(new Funding($nigeria, $sector, 2024, '100.00', FundingType::Public, $source, new \DateTimeImmutable('2024-01-01'), ValidationStatus::Validated));
        $this->entityManager->flush();

        $service = static::getContainer()->get(AlertEvaluationService::class);
        self::assertSame(1, $service->evaluate());

        $notifications = static::getContainer()->get(NotificationRepository::class)->findByUser($user, 1, 10);
        self::assertCount(1, $notifications);
        self::assertStringContainsString('Nigeria', $notifications[0]->getContent());
    }

    public function testGapCriticalRuleDoesNotReNotifyWhileTheEarlierAlertIsStillUnread(): void
    {
        $user = $this->createUser();
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

        $service = static::getContainer()->get(AlertEvaluationService::class);
        self::assertSame(1, $service->evaluate());
        self::assertSame(0, $service->evaluate());
    }

    public function testOpportunityClosingRuleNotifiesOnlyForAWindowClosingWithinFourteenDays(): void
    {
        $user = $this->createUser();
        $this->entityManager->persist(new AlertRule($user, AlertRuleType::OpportunityClosing));

        $source = new Source('Test Source', SourceType::InternalDemo, SourceReliability::Medium);
        $this->entityManager->persist($source);

        $closingSoon = new Opportunity('Closing Soon Grant', $source, 'Open to all.', '1000000.00');
        $closingSoon->setApplicationWindowClosesAt(new \DateTimeImmutable('+5 days'));
        $this->entityManager->persist($closingSoon);

        $closingLater = new Opportunity('Closing Later Grant', $source, 'Open to all.', '1000000.00');
        $closingLater->setApplicationWindowClosesAt(new \DateTimeImmutable('+30 days'));
        $this->entityManager->persist($closingLater);

        $this->entityManager->flush();

        $service = static::getContainer()->get(AlertEvaluationService::class);
        self::assertSame(1, $service->evaluate());

        $notifications = static::getContainer()->get(NotificationRepository::class)->findByUser($user, 1, 10);
        self::assertCount(1, $notifications);
        self::assertStringContainsString('Closing Soon Grant', $notifications[0]->getContent());
    }

    public function testScoreDegradedRuleNotifiesForADropOfAtLeastTenPoints(): void
    {
        $user = $this->createUser();
        $this->entityManager->persist(new AlertRule($user, AlertRuleType::ScoreDegraded));

        $country = new Country('Kenya', 'KEN', "Afrique de l'Est");
        $this->entityManager->persist($country);
        $this->entityManager->flush();

        $score = new Score(ScoreSubjectType::Country, (int) $country->getId(), 'equity', 80.0, 'A', []);
        $score->refresh(60.0, 'B', []); // previousValue becomes 80.0, a 20-point drop.
        $this->entityManager->persist($score);
        $this->entityManager->flush();

        $service = static::getContainer()->get(AlertEvaluationService::class);
        self::assertSame(1, $service->evaluate());

        $notifications = static::getContainer()->get(NotificationRepository::class)->findByUser($user, 1, 10);
        self::assertStringContainsString('Kenya', $notifications[0]->getContent());
        self::assertStringContainsString('80.0', $notifications[0]->getContent());
        self::assertStringContainsString('60.0', $notifications[0]->getContent());
    }

    public function testScoreDegradedRuleIgnoresAScoreThatHasNeverBeenRefreshed(): void
    {
        $user = $this->createUser();
        $this->entityManager->persist(new AlertRule($user, AlertRuleType::ScoreDegraded));

        $country = new Country('Kenya', 'KEN', "Afrique de l'Est");
        $this->entityManager->persist($country);
        $this->entityManager->flush();

        // previousValue is still null: this score was only ever computed once.
        $this->entityManager->persist(new Score(ScoreSubjectType::Country, (int) $country->getId(), 'equity', 40.0, 'D', []));
        $this->entityManager->flush();

        $service = static::getContainer()->get(AlertEvaluationService::class);
        self::assertSame(0, $service->evaluate());
    }

    public function testAbnormalFlowRuleNotifiesForAFlatHistoryFollowedByASpike(): void
    {
        $user = $this->createUser();
        $this->entityManager->persist(new AlertRule($user, AlertRuleType::AbnormalFlow));

        $country = new Country('Ghana', 'GHA', "Afrique de l'Ouest");
        $sector = new Sector('Renewable Energy');
        $source = new Source('Test Source', SourceType::InternalDemo, SourceReliability::Medium);
        $this->entityManager->persist($country);
        $this->entityManager->persist($sector);
        $this->entityManager->persist($source);

        // 3 flat years (mean=100, stddev=0) then a 4th year at 5x the mean -
        // triggers the "flat-history" fallback (latest > 3x mean).
        foreach ([2021, 2022, 2023] as $year) {
            $this->entityManager->persist(new Funding($country, $sector, $year, '100.00', FundingType::Public, $source, new \DateTimeImmutable($year.'-01-01'), ValidationStatus::Validated));
        }
        $this->entityManager->persist(new Funding($country, $sector, 2024, '500.00', FundingType::Public, $source, new \DateTimeImmutable('2024-01-01'), ValidationStatus::Validated));
        $this->entityManager->flush();

        $service = static::getContainer()->get(AlertEvaluationService::class);
        self::assertSame(1, $service->evaluate());

        $notifications = static::getContainer()->get(NotificationRepository::class)->findByUser($user, 1, 10);
        self::assertStringContainsString('Ghana', $notifications[0]->getContent());
    }

    public function testAbnormalFlowRuleIgnoresACountryWithFewerThanThreeYearsOfHistory(): void
    {
        $user = $this->createUser();
        $this->entityManager->persist(new AlertRule($user, AlertRuleType::AbnormalFlow));

        $country = new Country('Ghana', 'GHA', "Afrique de l'Ouest");
        $sector = new Sector('Renewable Energy');
        $source = new Source('Test Source', SourceType::InternalDemo, SourceReliability::Medium);
        $this->entityManager->persist($country);
        $this->entityManager->persist($sector);
        $this->entityManager->persist($source);

        $this->entityManager->persist(new Funding($country, $sector, 2023, '100.00', FundingType::Public, $source, new \DateTimeImmutable('2023-01-01'), ValidationStatus::Validated));
        $this->entityManager->persist(new Funding($country, $sector, 2024, '5000.00', FundingType::Public, $source, new \DateTimeImmutable('2024-01-01'), ValidationStatus::Validated));
        $this->entityManager->flush();

        $service = static::getContainer()->get(AlertEvaluationService::class);
        self::assertSame(0, $service->evaluate());
    }
}

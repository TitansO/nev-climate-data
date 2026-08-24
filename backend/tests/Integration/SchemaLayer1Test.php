<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Country;
use App\Entity\Enum\SourceReliability;
use App\Entity\Enum\SourceType;
use App\Entity\Enum\UserRole;
use App\Entity\Sector;
use App\Entity\Source;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SchemaLayer1Test extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
    }

    public function testCountryCanBePersistedAndFetched(): void
    {
        $country = new Country('Senegal', 'SEN', 'West Africa');
        $this->entityManager->persist($country);
        $this->entityManager->flush();
        $id = $country->getId();
        $this->entityManager->clear();

        $fetched = $this->entityManager->find(Country::class, $id);

        self::assertNotNull($fetched);
        self::assertSame('Senegal', $fetched->getName());
        self::assertSame('SEN', $fetched->getIsoCode());
    }

    public function testSectorCanBePersistedAndFetched(): void
    {
        $sector = new Sector('Renewable Energy');
        $this->entityManager->persist($sector);
        $this->entityManager->flush();
        $id = $sector->getId();
        $this->entityManager->clear();

        $fetched = $this->entityManager->find(Sector::class, $id);

        self::assertNotNull($fetched);
        self::assertSame('Renewable Energy', $fetched->getName());
    }

    public function testSourceCanBePersistedAndFetched(): void
    {
        $source = new Source('Internal Demo', SourceType::InternalDemo, SourceReliability::Medium);
        $this->entityManager->persist($source);
        $this->entityManager->flush();
        $id = $source->getId();
        $this->entityManager->clear();

        $fetched = $this->entityManager->find(Source::class, $id);

        self::assertNotNull($fetched);
        self::assertSame(SourceType::InternalDemo, $fetched->getType());
        self::assertSame(SourceReliability::Medium, $fetched->getReliability());
    }

    public function testUserCanBePersistedAndFetched(): void
    {
        $user = new User('Amina Diallo', 'amina@example.com', 'hashed-password', UserRole::InternalAnalyst);
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $id = $user->getId();
        $this->entityManager->clear();

        $fetched = $this->entityManager->find(User::class, $id);

        self::assertNotNull($fetched);
        self::assertSame('amina@example.com', $fetched->getEmail());
        self::assertSame(UserRole::InternalAnalyst, $fetched->getRole());
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
}

<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Country;
use App\Entity\Enum\FundingInstrument;
use App\Entity\Enum\SourceReliability;
use App\Entity\Enum\SourceType;
use App\Entity\Enum\UserRole;
use App\Entity\Opportunity;
use App\Entity\Project;
use App\Entity\Sector;
use App\Entity\Source;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET/PATCH /api/projects (C2.3) + GET /api/projects/{id}/matches (C2.2).
 */
final class ProjectControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager)) {
            $connection = $this->entityManager->getConnection();
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $this->entityManager->close();
        }
        parent::tearDown();
    }

    private function beginTransaction(KernelBrowser $client): void
    {
        $client->disableReboot();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
    }

    private function createUser(string $email): User
    {
        $user = new User('Amina Diallo', $email, password_hash('correct-horse-battery-staple', PASSWORD_DEFAULT), UserRole::InternalAnalyst);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * @return array{token: string, refresh_token: string}
     */
    private function loginAndGetTokens(KernelBrowser $client, string $email): array
    {
        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $email, 'password' => 'correct-horse-battery-staple']));

        return json_decode($client->getResponse()->getContent(), true);
    }

    /**
     * @return array{project: Project, renewableEnergy: Sector, agriculture: Sector}
     */
    private function seedProject(): array
    {
        $senegal = new Country('Senegal', 'SEN', "Afrique de l'Ouest");
        $renewableEnergy = new Sector('Renewable Energy');
        $agriculture = new Sector('Agriculture');
        foreach ([$senegal, $renewableEnergy, $agriculture] as $entity) {
            $this->entityManager->persist($entity);
        }

        $project = new Project('Off-grid Solar for Rural Casamance', $senegal, $renewableEnergy, '1000000.00', 'Test project.', 'Test Promoter');
        $project->setInstrumentSought(FundingInstrument::Grant);
        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return ['project' => $project, 'renewableEnergy' => $renewableEnergy, 'agriculture' => $agriculture];
    }

    public function testListWithoutAuthenticationFails(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/projects');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testListReturnsProjects(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $seed = $this->seedProject();
        $this->createUser('project-list@example.com');
        $tokens = $this->loginAndGetTokens($client, 'project-list@example.com');

        $client->request('GET', '/api/projects', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertCount(1, $data);
        self::assertSame($seed['project']->getId(), $data[0]['id']);
        self::assertSame('Senegal', $data[0]['country']);
        self::assertSame('SEN', $data[0]['countryIsoCode']);
        self::assertSame('grant', $data[0]['instrumentSought']);
        self::assertSame('scoping', $data[0]['pipelineStage']);
    }

    public function testListFiltersByStage(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $this->seedProject();
        $this->createUser('project-filter@example.com');
        $tokens = $this->loginAndGetTokens($client, 'project-filter@example.com');

        $client->request('GET', '/api/projects?stage=financed', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);

        self::assertResponseIsSuccessful();
        self::assertSame([], json_decode($client->getResponse()->getContent(), true)['data']);
    }

    public function testListRejectsAnInvalidStage(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $this->createUser('project-invalid-stage@example.com');
        $tokens = $this->loginAndGetTokens($client, 'project-invalid-stage@example.com');

        $client->request('GET', '/api/projects?stage=bogus', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testUpdateStageMovesTheProjectToTheGivenStage(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $seed = $this->seedProject();
        $this->createUser('project-update@example.com');
        $tokens = $this->loginAndGetTokens($client, 'project-update@example.com');

        $client->request('PATCH', '/api/projects/'.$seed['project']->getId(), server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']], content: json_encode(['stage' => 'fundraising']));

        self::assertResponseIsSuccessful();
        self::assertSame('fundraising', json_decode($client->getResponse()->getContent(), true)['pipelineStage']);
    }

    public function testUpdateStageReturns404ForAnUnknownProject(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $this->createUser('project-404@example.com');
        $tokens = $this->loginAndGetTokens($client, 'project-404@example.com');

        $client->request('PATCH', '/api/projects/999999', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']], content: json_encode(['stage' => 'fundraising']));

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testUpdateStageRejectsAMissingStageField(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $seed = $this->seedProject();
        $this->createUser('project-missing-stage@example.com');
        $tokens = $this->loginAndGetTokens($client, 'project-missing-stage@example.com');

        $client->request('PATCH', '/api/projects/'.$seed['project']->getId(), server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']], content: json_encode([]));

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testUpdateStageRejectsAnInvalidStageValue(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $seed = $this->seedProject();
        $this->createUser('project-invalid-value@example.com');
        $tokens = $this->loginAndGetTokens($client, 'project-invalid-value@example.com');

        $client->request('PATCH', '/api/projects/'.$seed['project']->getId(), server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']], content: json_encode(['stage' => 'bogus']));

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testMatchesReturns404ForAnUnknownProject(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $this->createUser('matches-404@example.com');
        $tokens = $this->loginAndGetTokens($client, 'matches-404@example.com');

        $client->request('GET', '/api/projects/999999/matches', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testMatchesRejectsAnInvalidLimit(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $seed = $this->seedProject();
        $this->createUser('matches-invalid-limit@example.com');
        $tokens = $this->loginAndGetTokens($client, 'matches-invalid-limit@example.com');

        $client->request('GET', '/api/projects/'.$seed['project']->getId().'/matches?limit=0', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    /**
     * Project : Senegal / Renewable Energy / Grant / 1,000,000 sought.
     *
     * Opportunity A - perfect match on every component:
     *   same sector, "tous les pays" catch-all, "subvention" (= Grant),
     *   envelope 2,000,000 (project fits comfortably), always open
     *   (no window dates at all) -> every component 1.0, score 1.0.
     *
     * Opportunity B - near-total mismatch:
     *   different sector (Agriculture), no country signal ("G5 Sahel"
     *   text, no catch-all, no "Senegal"/"Afrique de l'Ouest"), no
     *   instrument keyword ("garantie" != Grant), envelope smaller than
     *   sought (500,000, ratio 2 -> partial credit only), already closed.
     *   Expected score: 0.1 * (1 - log10(2)) ≈ 0.06990.
     *
     * Verifies both the ranking (A before B) and the exact score/breakdown
     * math, not just "a list came back".
     */
    public function testMatchesRanksOpportunitiesByScoreAndComputesTheExactFormula(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $seed = $this->seedProject();

        $source = new Source('Test Source', SourceType::InternalDemo, SourceReliability::Medium);
        $this->entityManager->persist($source);

        $perfectMatch = new Opportunity('Perfect Match Facility', $source, 'Ouvert à tous les pays NEV. Instruments proposés : subvention.', '2000000.00');
        $perfectMatch->setSector($seed['renewableEnergy']);
        $this->entityManager->persist($perfectMatch);

        $poorMatch = new Opportunity('Sahel Guarantee Window', $source, 'Réservé aux pays du G5 Sahel. Instruments proposés : garantie.', '500000.00');
        $poorMatch->setSector($seed['agriculture']);
        $poorMatch->setApplicationWindowClosesAt(new \DateTimeImmutable('yesterday'));
        $this->entityManager->persist($poorMatch);

        $this->entityManager->flush();

        $this->createUser('matches-formula@example.com');
        $tokens = $this->loginAndGetTokens($client, 'matches-formula@example.com');

        $client->request('GET', '/api/projects/'.$seed['project']->getId().'/matches', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertCount(2, $data);

        self::assertSame($perfectMatch->getId(), $data[0]['opportunityId']);
        self::assertEqualsWithDelta(1.0, $data[0]['score'], 0.0001);
        self::assertSame(['sector' => 1.0, 'country' => 1.0, 'instrument' => 1.0, 'amount' => 1.0, 'window' => 1.0], $data[0]['breakdown']);
        self::assertSame('open', $data[0]['opportunityStatus']);

        self::assertSame($poorMatch->getId(), $data[1]['opportunityId']);
        self::assertEqualsWithDelta(0.1 * (1 - log10(2)), $data[1]['score'], 0.0001);
        self::assertEqualsWithDelta(0.0, $data[1]['breakdown']['sector'], 0.0001);
        self::assertEqualsWithDelta(0.0, $data[1]['breakdown']['country'], 0.0001);
        self::assertEqualsWithDelta(0.0, $data[1]['breakdown']['instrument'], 0.0001);
        self::assertEqualsWithDelta(1 - log10(2), $data[1]['breakdown']['amount'], 0.0001);
        self::assertEqualsWithDelta(0.0, $data[1]['breakdown']['window'], 0.0001);
        self::assertSame('closed', $data[1]['opportunityStatus']);
    }

    public function testMatchesRespectsTheLimitParameter(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $seed = $this->seedProject();

        $source = new Source('Test Source', SourceType::InternalDemo, SourceReliability::Medium);
        $this->entityManager->persist($source);
        for ($i = 0; $i < 3; ++$i) {
            $opportunity = new Opportunity('Opportunity '.$i, $source, 'Ouvert à tous les pays NEV.', '2000000.00');
            $this->entityManager->persist($opportunity);
        }
        $this->entityManager->flush();

        $this->createUser('matches-limit@example.com');
        $tokens = $this->loginAndGetTokens($client, 'matches-limit@example.com');

        $client->request('GET', '/api/projects/'.$seed['project']->getId().'/matches?limit=2', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);

        self::assertResponseIsSuccessful();
        self::assertCount(2, json_decode($client->getResponse()->getContent(), true)['data']);
    }
}

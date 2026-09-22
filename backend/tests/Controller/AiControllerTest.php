<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * POST /api/ai/ask (C2.1) - the `ai` service itself is always mocked here
 * (MockHttpClient swapped into the container): this suite verifies the
 * proxy's own behavior (auth, validation, quota, error translation), not
 * the real RAG round-trip - that's ai/tests/test_rag.py's job.
 */
final class AiControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private static int $userCounter = 0;

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

    /**
     * A fresh email/user per call (Postgres sequences don't roll back with
     * the transaction, see NotificationControllerTest/FundingExportTest's
     * own reasoning) - each test's rate-limiter bucket, keyed by user id,
     * therefore always starts empty regardless of what earlier tests
     * already consumed.
     */
    private function createUser(UserRole $role = UserRole::InternalAnalyst): User
    {
        $email = \sprintf('ai-copilot-%d@example.com', ++self::$userCounter);
        $user = new User('Amina Diallo', $email, password_hash('correct-horse-battery-staple', PASSWORD_DEFAULT), $role);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * @return array{token: string, refresh_token: string}
     */
    private function loginAndGetTokens(KernelBrowser $client, User $user): array
    {
        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $user->getEmail(), 'password' => 'correct-horse-battery-staple']));

        return json_decode($client->getResponse()->getContent(), true);
    }

    private function mockAiService(MockHttpClient $mockClient): void
    {
        static::getContainer()->set(HttpClientInterface::class, $mockClient);
    }

    public function testAskWithoutAuthenticationFails(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/ai/ask', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['question' => 'Quel est le financement total pour le Sénégal ?']));

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testAskForwardsTheQuestionAndReturnsTheAiServiceResponseVerbatim(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $user = $this->createUser();
        $tokens = $this->loginAndGetTokens($client, $user);

        $capturedRequests = [];
        $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedRequests) {
            $capturedRequests[] = ['method' => $method, 'url' => $url, 'body' => $options['body']];

            return new MockResponse(json_encode(['available' => true, 'answer' => 'Le Sénégal a reçu 400 000 USD.', 'sources' => ['funding:aggregate']]), ['http_code' => 200]);
        });
        $this->mockAiService($mockClient);

        $client->request(
            'POST',
            '/api/ai/ask',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']],
            content: json_encode(['question' => 'Quel est le financement total pour le Sénégal ?'])
        );

        self::assertResponseIsSuccessful();
        self::assertCount(1, $capturedRequests);
        self::assertSame('POST', $capturedRequests[0]['method']);
        self::assertStringEndsWith('/ai/ask', $capturedRequests[0]['url']);
        self::assertSame(['question' => 'Quel est le financement total pour le Sénégal ?'], json_decode($capturedRequests[0]['body'], true));

        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertTrue($body['available']);
        self::assertSame('Le Sénégal a reçu 400 000 USD.', $body['answer']);
        self::assertSame(['funding:aggregate'], $body['sources']);
    }

    public function testAskPassesThroughAnUnavailableAnswerAsIs(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $user = $this->createUser();
        $tokens = $this->loginAndGetTokens($client, $user);

        $this->mockAiService(new MockHttpClient(fn () => new MockResponse(json_encode(['available' => false, 'answer' => null, 'sources' => []]), ['http_code' => 200])));

        $client->request('POST', '/api/ai/ask', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']], content: json_encode(['question' => 'Une question sans rapport avec le corpus ?']));

        self::assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertFalse($body['available']);
    }

    public function testAskRejectsAMissingQuestion(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $user = $this->createUser();
        $tokens = $this->loginAndGetTokens($client, $user);

        $client->request('POST', '/api/ai/ask', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']], content: json_encode([]));

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testAskRejectsATooShortQuestion(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $user = $this->createUser();
        $tokens = $this->loginAndGetTokens($client, $user);

        $client->request('POST', '/api/ai/ask', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']], content: json_encode(['question' => 'Hi']));

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testAskRejectsATooLongQuestion(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $user = $this->createUser();
        $tokens = $this->loginAndGetTokens($client, $user);

        $client->request('POST', '/api/ai/ask', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']], content: json_encode(['question' => str_repeat('a', 2001)]));

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testAskRejectsAnInvalidJsonBody(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $user = $this->createUser();
        $tokens = $this->loginAndGetTokens($client, $user);

        $client->request('POST', '/api/ai/ask', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']], content: 'not json');

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testAskReturns503WhenTheAiServiceIsUnreachable(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $user = $this->createUser();
        $tokens = $this->loginAndGetTokens($client, $user);

        $this->mockAiService(new MockHttpClient(fn () => new MockResponse('', ['error' => 'Connection refused'])));

        $client->request('POST', '/api/ai/ask', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']], content: json_encode(['question' => 'Quel est le financement total pour le Sénégal ?']));

        self::assertSame(503, $client->getResponse()->getStatusCode());
    }

    /**
     * ExternalPartner's daily AI Copilot quota is 30 (config/packages/
     * rate_limiter.yaml, "ai_ask_external_partner") - verified live against
     * the real limiter, not mocked (only the ai service response itself is
     * mocked, so the 30 accepted calls don't need real network access).
     */
    public function testAskQuotaIsEnforcedByRole(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $user = $this->createUser(UserRole::ExternalPartner);
        $tokens = $this->loginAndGetTokens($client, $user);
        $this->mockAiService(new MockHttpClient(fn () => new MockResponse(json_encode(['available' => true, 'answer' => 'ok', 'sources' => []]), ['http_code' => 200])));

        for ($i = 0; $i < 30; ++$i) {
            $client->request('POST', '/api/ai/ask', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']], content: json_encode(['question' => 'Question numéro '.$i.' ?']));
            self::assertResponseIsSuccessful();
        }

        $client->request('POST', '/api/ai/ask', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']], content: json_encode(['question' => 'Une question de trop ?']));

        self::assertSame(429, $client->getResponse()->getStatusCode());
    }

    /**
     * C3.5 : POST /api/greenwashing/check - même patron que /api/ai/ask
     * (AiController::checkGreenwashing() -> App\Service\GreenwashingService,
     * lui-même un simple appel HTTP vers `ai/` mocké ici).
     */
    private function greenwashingPayload(array $overrides = []): string
    {
        return json_encode(array_merge([
            'title' => 'Projet solaire communautaire',
            'description' => 'Installation de panneaux solaires pour 12 villages.',
            'sector' => 'Renewable Energy',
            'amountUsd' => 500000,
        ], $overrides));
    }

    public function testCheckGreenwashingWithoutAuthenticationFails(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/greenwashing/check', server: ['CONTENT_TYPE' => 'application/json'], content: $this->greenwashingPayload());

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testCheckGreenwashingForwardsTheRequestAndReturnsTheAiServiceResponse(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $user = $this->createUser();
        $tokens = $this->loginAndGetTokens($client, $user);

        $capturedRequests = [];
        $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedRequests) {
            $capturedRequests[] = ['method' => $method, 'url' => $url, 'body' => $options['body']];

            return new MockResponse(json_encode([
                'available' => true,
                'cached' => false,
                'risk_level' => 'high',
                'red_flags' => ['Montant disproportionné par rapport au descriptif'],
                'reasoning' => 'Le montant demandé est très supérieur aux projets comparables de ce secteur.',
            ]), ['http_code' => 200]);
        });
        $this->mockAiService($mockClient);

        $client->request('POST', '/api/greenwashing/check', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']], content: $this->greenwashingPayload());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $capturedRequests);
        self::assertStringEndsWith('/ai/greenwashing', $capturedRequests[0]['url']);
        $sentBody = json_decode($capturedRequests[0]['body'], true);
        self::assertSame('Projet solaire communautaire', $sentBody['title']);
        self::assertSame('Renewable Energy', $sentBody['sector']);
        self::assertSame(500000.0, $sentBody['amount_usd']);

        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertTrue($body['available']);
        self::assertSame('high', $body['riskLevel']);
        self::assertSame(['Montant disproportionné par rapport au descriptif'], $body['redFlags']);
    }

    public function testCheckGreenwashingRejectsAMissingTitle(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $user = $this->createUser();
        $tokens = $this->loginAndGetTokens($client, $user);

        $client->request('POST', '/api/greenwashing/check', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']], content: $this->greenwashingPayload(['title' => '']));

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testCheckGreenwashingRejectsAMissingAmount(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $user = $this->createUser();
        $tokens = $this->loginAndGetTokens($client, $user);

        $client->request('POST', '/api/greenwashing/check', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']], content: $this->greenwashingPayload(['amountUsd' => null]));

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testCheckGreenwashingReturns503WhenTheAiServiceIsUnreachable(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $user = $this->createUser();
        $tokens = $this->loginAndGetTokens($client, $user);

        $this->mockAiService(new MockHttpClient(fn () => new MockResponse('', ['error' => 'Connection refused'])));

        $client->request('POST', '/api/greenwashing/check', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']], content: $this->greenwashingPayload());

        self::assertSame(503, $client->getResponse()->getStatusCode());
    }

    /**
     * ExternalPartner's daily greenwashing-check quota is 30
     * (config/packages/rate_limiter.yaml, "greenwashing_external_partner") -
     * separate budget from ai_ask_external_partner, same reasoning as
     * classify_external_partner.
     */
    public function testCheckGreenwashingQuotaIsEnforcedByRole(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $user = $this->createUser(UserRole::ExternalPartner);
        $tokens = $this->loginAndGetTokens($client, $user);
        $this->mockAiService(new MockHttpClient(fn () => new MockResponse(json_encode(['available' => true, 'cached' => false, 'risk_level' => 'low', 'red_flags' => [], 'reasoning' => 'ok']), ['http_code' => 200])));

        for ($i = 0; $i < 30; ++$i) {
            $client->request('POST', '/api/greenwashing/check', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']], content: $this->greenwashingPayload(['title' => 'Projet numéro '.$i]));
            self::assertResponseIsSuccessful();
        }

        $client->request('POST', '/api/greenwashing/check', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']], content: $this->greenwashingPayload(['title' => 'Un projet de trop']));

        self::assertSame(429, $client->getResponse()->getStatusCode());
    }

    public function testGreenwashingEndpointIsDocumentedInSwagger(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/doc.json');

        self::assertResponseIsSuccessful();
        $spec = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('/api/greenwashing/check', $spec['paths']);
    }
}

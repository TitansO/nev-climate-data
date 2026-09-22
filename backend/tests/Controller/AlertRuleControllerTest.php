<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AlertRuleControllerTest extends WebTestCase
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

    private function createUser(): User
    {
        $email = \sprintf('alert-rule-%d@example.com', ++self::$userCounter);
        $user = new User('Amina Diallo', $email, password_hash('correct-horse-battery-staple', PASSWORD_DEFAULT), UserRole::InternalAnalyst);
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

    public function testListWithoutAuthenticationFails(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/alert-rules');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testCreateRejectsAnInvalidRuleType(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $user = $this->createUser();
        $tokens = $this->loginAndGetTokens($client, $user);

        $client->request('POST', '/api/alert-rules', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']], content: json_encode(['ruleType' => 'not_a_real_type']));

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testCreateRejectsAMissingRuleType(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $user = $this->createUser();
        $tokens = $this->loginAndGetTokens($client, $user);

        $client->request('POST', '/api/alert-rules', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']], content: json_encode([]));

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testCreateThenListRoundTrips(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $user = $this->createUser();
        $tokens = $this->loginAndGetTokens($client, $user);

        $client->request('POST', '/api/alert-rules', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']], content: json_encode(['ruleType' => 'gap_critical']));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $created = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('gap_critical', $created['ruleType']);
        self::assertTrue($created['active']);

        $client->request('GET', '/api/alert-rules', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);
        self::assertResponseIsSuccessful();
        $list = json_decode($client->getResponse()->getContent(), true);
        self::assertCount(1, $list);
        self::assertSame($created['id'], $list[0]['id']);
    }

    public function testCreatingTheSameRuleTypeTwiceIsIdempotent(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $user = $this->createUser();
        $tokens = $this->loginAndGetTokens($client, $user);

        $client->request('POST', '/api/alert-rules', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']], content: json_encode(['ruleType' => 'abnormal_flow']));
        $first = json_decode($client->getResponse()->getContent(), true);

        $client->request('POST', '/api/alert-rules', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']], content: json_encode(['ruleType' => 'abnormal_flow']));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $second = json_decode($client->getResponse()->getContent(), true);

        self::assertSame($first['id'], $second['id']);

        $client->request('GET', '/api/alert-rules', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);
        self::assertCount(1, json_decode($client->getResponse()->getContent(), true));
    }

    public function testListOnlyReturnsTheCallersOwnRules(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $me = $this->createUser();
        $someoneElse = $this->createUser();
        $myTokens = $this->loginAndGetTokens($client, $me);
        $otherTokens = $this->loginAndGetTokens($client, $someoneElse);

        $client->request('POST', '/api/alert-rules', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$otherTokens['token']], content: json_encode(['ruleType' => 'score_degraded']));
        self::assertSame(201, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/alert-rules', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$myTokens['token']]);

        self::assertSame([], json_decode($client->getResponse()->getContent(), true));
    }

    public function testUpdateTogglesActiveOffThenOn(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $user = $this->createUser();
        $tokens = $this->loginAndGetTokens($client, $user);

        $client->request('POST', '/api/alert-rules', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']], content: json_encode(['ruleType' => 'gap_critical']));
        $created = json_decode($client->getResponse()->getContent(), true);

        $client->request('PATCH', '/api/alert-rules/'.$created['id'], server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']], content: json_encode(['active' => false]));
        self::assertResponseIsSuccessful();
        self::assertFalse(json_decode($client->getResponse()->getContent(), true)['active']);

        $client->request('PATCH', '/api/alert-rules/'.$created['id'], server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']], content: json_encode(['active' => true]));
        self::assertTrue(json_decode($client->getResponse()->getContent(), true)['active']);
    }

    public function testUpdateRejectsANonBooleanActive(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $user = $this->createUser();
        $tokens = $this->loginAndGetTokens($client, $user);
        $client->request('POST', '/api/alert-rules', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']], content: json_encode(['ruleType' => 'gap_critical']));
        $created = json_decode($client->getResponse()->getContent(), true);

        $client->request('PATCH', '/api/alert-rules/'.$created['id'], server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']], content: json_encode(['active' => 'yes']));

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testUpdateReturns404ForAnotherUsersRule(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $owner = $this->createUser();
        $attacker = $this->createUser();
        $ownerTokens = $this->loginAndGetTokens($client, $owner);
        $client->request('POST', '/api/alert-rules', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$ownerTokens['token']], content: json_encode(['ruleType' => 'gap_critical']));
        $created = json_decode($client->getResponse()->getContent(), true);
        $attackerTokens = $this->loginAndGetTokens($client, $attacker);

        $client->request('PATCH', '/api/alert-rules/'.$created['id'], server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$attackerTokens['token']], content: json_encode(['active' => false]));

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testAlertRuleEndpointsAreDocumentedInSwagger(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/doc.json');

        self::assertResponseIsSuccessful();
        $spec = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('/api/alert-rules', $spec['paths']);
    }
}

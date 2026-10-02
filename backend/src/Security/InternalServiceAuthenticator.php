<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Audit post-C3 2026-10-02 (P-01) : authentifie les requetes service-a-
 * service de ai/ingest.py (F6, reindexation du corpus RAG) portant un
 * en-tete `X-Internal-Token` valide. Meme jeton partage et meme
 * comparaison a temps constant (hash_equals) que
 * App\Controller\InternalAlertsController, qui notait deja - de maniere
 * perimee depuis la restructuration des acces du 24/09/2026 - que
 * ai/ingest.py s'appuyait sur ^/api/reports et ^/api/analytics en
 * PUBLIC_ACCESS : ces deux routes sont ROLE_USER depuis cette date, et
 * ai/ingest.py les appelait donc sans aucune authentification, provoquant
 * un 401 qui faisait planter tout le pipeline de reindexation (confirme en
 * direct lors de l'audit du 02/10/2026).
 *
 * Contrairement a InternalAlertsController (endpoint PUBLIC_ACCESS dedie,
 * ou le jeton est l'unique verification), ici l'en-tete authentifie une
 * identite synthetique ROLE_USER (InternalServiceUser) sur des endpoints
 * ^/api/reports et ^/api/analytics qui restent par ailleurs normalement
 * accessibles aux utilisateurs humains via JWT - supports() ne reclame que
 * les requetes portant reellement l'en-tete, donc les requetes JWT
 * existantes ne sont pas affectees (meme principe que ApiKeyAuthenticator).
 *
 * supports() est volontairement restreint a ces deux prefixes precis,
 * jamais "n'importe quelle requete portant l'en-tete" : une regression
 * trouvee en verifiant ce correctif - un X-Internal-Token (meme invalide)
 * sur POST /api/internal/alerts/evaluate etait intercepte ici en premier,
 * transformant le 403 (AccessDeniedHttpException, le propre controle
 * independant de InternalAlertsController) en 401, et cassant
 * InternalAlertsControllerTest::testEvaluateRejectsAWrongToken. Les deux
 * mecanismes doivent rester strictement separes : celui-ci ne doit jamais
 * voir une requete destinee a un endpoint qui gere deja son propre jeton.
 */
final class InternalServiceAuthenticator extends AbstractAuthenticator
{
    private const HEADER_NAME = 'X-Internal-Token';

    /** @var list<string> */
    private const SUPPORTED_PATH_PREFIXES = ['/api/reports', '/api/analytics'];

    public function __construct(
        private readonly string $internalTriggerToken,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        if (!$request->headers->has(self::HEADER_NAME)) {
            return false;
        }

        $path = $request->getPathInfo();
        foreach (self::SUPPORTED_PATH_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function authenticate(Request $request): Passport
    {
        $provided = (string) $request->headers->get(self::HEADER_NAME);
        if ('' === $this->internalTriggerToken || !hash_equals($this->internalTriggerToken, $provided)) {
            throw new CustomUserMessageAuthenticationException('Invalid internal token.');
        }

        $user = new InternalServiceUser();

        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn () => $user));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(
            ['code' => Response::HTTP_UNAUTHORIZED, 'message' => $exception->getMessage()],
            Response::HTTP_UNAUTHORIZED,
        );
    }
}

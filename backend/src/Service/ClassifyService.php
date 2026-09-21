<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * C2.7 : appel HTTP vers le service `ai/` (POST /ai/classify) - factorisé
 * ici pour être appelé à la fois par App\Controller\AiController::classify()
 * (utilitaire public, ne persiste rien) et App\Controller\ProjectController::
 * classifyProject() (persiste le résultat sur Project::$taxonomyClass) sans
 * dupliquer la traduction HTTP -> JSON ni la gestion d'erreur. Même
 * raisonnement 503 (jamais 200 `{available:false}`) qu'AiController::ask()
 * pour une vraie panne du service `ai/`.
 */
final class ClassifyService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $aiServiceUrl,
    ) {
    }

    /**
     * @return array{available: bool, cached: bool, taxonomyClass: string, activityCategory: string, reasoning: string}
     */
    public function classify(string $title, string $description, string $sector): array
    {
        try {
            $response = $this->httpClient->request('POST', rtrim($this->aiServiceUrl, '/').'/ai/classify', [
                'json' => ['title' => $title, 'description' => $description, 'sector' => $sector],
                'timeout' => 30,
            ]);
            $body = $response->toArray();
        } catch (HttpClientExceptionInterface $exception) {
            throw new ServiceUnavailableHttpException(null, 'Service IA temporairement indisponible.', $exception);
        }

        return [
            'available' => $body['available'],
            'cached' => $body['cached'],
            'taxonomyClass' => $body['taxonomy_class'],
            'activityCategory' => $body['activity_category'],
            'reasoning' => $body['reasoning'],
        ];
    }
}

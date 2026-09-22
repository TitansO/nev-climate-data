<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * C3.5 : appel HTTP vers le service `ai/` (POST /ai/greenwashing) - même
 * patron que App\Service\ClassifyService (C2.7) : traduction HTTP -> JSON,
 * 503 (jamais 200 `{available:false}`) pour une vraie panne du service
 * `ai/`.
 */
final class GreenwashingService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $aiServiceUrl,
    ) {
    }

    /**
     * @return array{available: bool, cached: bool, riskLevel: string, redFlags: list<string>, reasoning: string}
     */
    public function check(string $title, string $description, float $amountUsd, string $sector, ?string $taxonomyClass = null): array
    {
        try {
            $response = $this->httpClient->request('POST', rtrim($this->aiServiceUrl, '/').'/ai/greenwashing', [
                'json' => [
                    'title' => $title,
                    'description' => $description,
                    'amount_usd' => $amountUsd,
                    'sector' => $sector,
                    'taxonomy_class' => $taxonomyClass,
                ],
                'timeout' => 30,
            ]);
            $body = $response->toArray();
        } catch (HttpClientExceptionInterface $exception) {
            throw new ServiceUnavailableHttpException(null, 'Service IA temporairement indisponible.', $exception);
        }

        return [
            'available' => $body['available'],
            'cached' => $body['cached'],
            'riskLevel' => $body['risk_level'],
            'redFlags' => $body['red_flags'],
            'reasoning' => $body['reasoning'],
        ];
    }
}

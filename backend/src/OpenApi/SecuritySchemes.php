<?php

declare(strict_types=1);

namespace App\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Declares the OpenAPI security schemes referenced by controllers'
 * `security: [...]` attributes (e.g. App\Controller\ApiKeyController).
 *
 * Deliberately isolated in its own route-less class: swagger-php rejects a
 * class that mixes a root SecurityScheme attribute with per-method root
 * Operation attributes (Get/Post/Delete) in the same class — see
 * ApiKeyController's class docblock. NelmioApiDocBundle's describer scans
 * the whole src/ tree for OpenAPI attributes, not just routed controllers,
 * so this class is picked up without needing a route.
 */
#[OA\SecurityScheme(securityScheme: 'bearerAuth', type: 'http', scheme: 'bearer', bearerFormat: 'JWT')]
#[OA\SecurityScheme(securityScheme: 'apiKeyAuth', type: 'apiKey', in: 'header', name: 'X-API-Key')]
final class SecuritySchemes
{
}

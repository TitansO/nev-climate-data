<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum ApiKeyStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
}

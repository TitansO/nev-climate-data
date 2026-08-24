<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum ValidationStatus: string
{
    case Demo = 'demo';
    case Validated = 'validated';
}

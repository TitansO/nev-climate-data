<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum SourceReliability: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}

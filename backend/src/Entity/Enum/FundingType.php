<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum FundingType: string
{
    case Public = 'public';
    case Private = 'private';
    case Multilateral = 'multilateral';
}

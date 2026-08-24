<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum ReportStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}

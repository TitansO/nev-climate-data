<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum SourceType: string
{
    case OfficialApi = 'official_api';
    case PdfReport = 'pdf_report';
    case GreenAccessEvent = 'green_access_event';
    case InternalDemo = 'internal_demo';
}

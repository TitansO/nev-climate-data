<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum NotificationType: string
{
    case NewReport = 'new_report';
    case NewData = 'new_data';
}

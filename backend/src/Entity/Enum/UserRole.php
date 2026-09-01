<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum UserRole: string
{
    // Above Admin: the only role allowed to manage other users' accounts
    // and roles (see App\Controller\UserController) - Admin itself has no
    // such power, matching the cahier des charges 5.2 role list, which
    // never granted Admin that capability in the first place.
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case InternalAnalyst = 'internal_analyst';
    case ExternalPartner = 'external_partner';
}

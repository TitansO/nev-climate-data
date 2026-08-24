<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Enum\UserRole;

/**
 * Daily request quota granted to a newly-generated API key, by role.
 *
 * PROVISIONAL (A1.5): neither the cahier des charges nor the implementation
 * plan defines official quota figures — the schema design doc
 * (docs/superpowers/specs/2026-08-22-a13-timescaledb-schema-design.md)
 * only specifies that `ApiKey.quota` holds "a daily request quota", without
 * numbers. These values are reasonable demonstration defaults, centralized
 * here so they're easy to revise once the product/business team sets real
 * limits — do not scatter magic numbers elsewhere in the codebase.
 */
final class ApiKeyQuotaPolicy
{
    private const QUOTAS = [
        UserRole::Admin->value => 100_000,
        UserRole::InternalAnalyst->value => 20_000,
        UserRole::ExternalPartner->value => 5_000,
    ];

    public function quotaForRole(UserRole $role): int
    {
        return self::QUOTAS[$role->value];
    }
}

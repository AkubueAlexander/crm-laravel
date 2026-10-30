<?php

namespace App\Domain\Hr\Hris;

/**
 * 13.3: one employee as reported by an external HRIS (Gusto / Rippling / BambooHR).
 * Read-only data carrier; the CRM never writes payroll or benefits data back.
 */
final class HrisEmployeeRecord
{
    public function __construct(
        public readonly string $externalId,
        public readonly string $name,
        public readonly ?string $email = null,
        public readonly ?string $jobTitle = null,
        public readonly ?string $hireDate = null,
        public readonly bool $isActive = true,
    ) {
    }
}

<?php

namespace App\Domain\Hr\Hris;

/**
 * 13.3: the seam for an external HRIS. Payroll, benefits and tax compliance are a
 * deliberate non-goal (see docs/13.3-hris.md); an adapter only reads the employee roster.
 */
interface HrisAdapter
{
    public function name(): string;

    /**
     * @return array<int, HrisEmployeeRecord>
     */
    public function fetchEmployees(): array;
}

<?php

namespace App\Domain\Hr\Actions;

use App\Models\Employee;
use App\Models\HrUnit;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * 13.1b: creates or edits an employee. An edit replaces the profile (absent optional fields are cleared) except
 * is_active, which is kept when omitted. The team and territory must be units of the actor's tenant of the right kind
 * (and active, unless the employee already holds that very unit); the optional login user must belong to the tenant and
 * be linked to one employee only; email is unique per tenant, case-insensitively. Employees are deactivated, never
 * deleted. Needs hr.manage.
 */
final class SaveEmployeeAction
{
    /** @param array<string, mixed> $data */
    public function execute(User $actor, ?int $employeeId, array $data): Employee
    {
        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);
        Gate::forUser($actor)->authorize('hr.manage');

        $clean = static function (mixed $v): ?string {
            $v = trim((string) ($v ?? ''));

            return $v === '' ? null : $v;
        };
        $id = static function (mixed $v): ?int {
            return $v === null || $v === '' ? null : (int) $v;
        };

        $name = trim((string) ($data['name'] ?? ''));
        $email = $clean($data['email'] ?? null);
        $phone = $clean($data['phone'] ?? null);
        $jobTitle = $clean($data['job_title'] ?? null);
        $hireDate = $clean($data['hire_date'] ?? null);
        $userId = $id($data['user_id'] ?? null);
        $teamId = $id($data['team_id'] ?? null);
        $territoryId = $id($data['territory_id'] ?? null);
        $active = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : null;

        $errors = [];
        if ($name === '' || mb_strlen($name) > 160) {
            $errors['name'] = ['Enter a name (max 160 characters).'];
        }
        if ($email !== null && (mb_strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $errors['email'] = ['Enter a valid email address.'];
        }
        if ($phone !== null && mb_strlen($phone) > 40) {
            $errors['phone'] = ['Phone must be 40 characters or fewer.'];
        }
        if ($jobTitle !== null && mb_strlen($jobTitle) > 120) {
            $errors['job_title'] = ['Job title must be 120 characters or fewer.'];
        }
        if ($hireDate !== null) {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $hireDate);
            if ($d === false || $d->format('Y-m-d') !== $hireDate) {
                $errors['hire_date'] = ['Use the format YYYY-MM-DD.'];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        try {
            return DB::transaction(function () use ($actor, $employeeId, $tenantId, $name, $email, $phone, $jobTitle, $hireDate, $userId, $teamId, $territoryId, $active): Employee {
                $employee = null;
                if ($employeeId !== null) {
                    // Explicit tenant predicate on top of the global scope: another tenant's id is a 404.
                    $employee = Employee::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($employeeId);
                }
                $currentId = $employee?->id;

                $errors = [];

                if ($email !== null) {
                    $dup = Employee::query()
                        ->where('tenant_id', $tenantId)
                        ->whereRaw('lower(email) = ?', [mb_strtolower($email)])
                        ->when($currentId !== null, fn ($q) => $q->where('id', '<>', $currentId))
                        ->exists();
                    if ($dup) {
                        $errors['email'] = ['An employee with this email already exists.'];
                    }
                }

                if ($userId !== null) {
                    // User has no tenant scope: the tenant predicate here is the whole check.
                    $user = User::query()->where('tenant_id', $tenantId)->find($userId);
                    if ($user === null) {
                        $errors['user_id'] = ['Choose a user from your workspace.'];
                    } elseif (Employee::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->when($currentId !== null, fn ($q) => $q->where('id', '<>', $currentId))->exists()) {
                        $errors['user_id'] = ['This user is already linked to another employee.'];
                    }
                }

                $teamError = $this->unitError($tenantId, $teamId, HrUnit::KIND_TEAM, $employee?->team_id);
                if ($teamError !== null) {
                    $errors['team_id'] = [$teamError];
                }
                $territoryError = $this->unitError($tenantId, $territoryId, HrUnit::KIND_TERRITORY, $employee?->territory_id);
                if ($territoryError !== null) {
                    $errors['territory_id'] = [$territoryError];
                }

                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }

                $employee ??= new Employee();
                $values = [
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'job_title' => $jobTitle,
                    'hire_date' => $hireDate,
                    'user_id' => $userId,
                    'team_id' => $teamId,
                    'territory_id' => $territoryId,
                    'is_active' => $active ?? ($employee->exists ? (bool) $employee->is_active : true),
                ];

                if (! $employee->exists) {
                    $values += ['tenant_id' => $tenantId, 'created_by' => $actor->id];
                }

                $employee->forceFill($values)->save();

                return $employee;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Race guard: a concurrent save took the same email or user.
            $field = str_contains($e->getMessage(), 'employees_tenant_email_unique') ? 'email' : 'user_id';
            throw ValidationException::withMessages([$field => [$field === 'email' ? 'An employee with this email already exists.' : 'This user is already linked to another employee.']]);
        }
    }

    private function unitError(int $tenantId, ?int $unitId, string $kind, ?int $currentUnitId): ?string
    {
        if ($unitId === null) {
            return null;
        }

        $unit = HrUnit::query()->where('tenant_id', $tenantId)->where('kind', $kind)->find($unitId);

        if ($unit === null) {
            return "Choose a {$kind} from your workspace.";
        }
        if (! $unit->is_active && $unitId !== $currentUnitId) {
            return "This {$kind} is inactive.";
        }

        return null;
    }
}

<?php

namespace App\Domain\Hr\Actions;

use App\Models\HrUnit;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * 13.1a: creates or edits a team or a territory. Names are unique per tenant and kind, case-insensitively. Units are
 * deactivated, never deleted (employees will reference them). Needs hr.manage.
 */
final class SaveHrUnitAction
{
    /** @param array<string, mixed> $data */
    public function execute(User $actor, string $kind, ?int $unitId, array $data): HrUnit
    {
        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);
        Gate::forUser($actor)->authorize('hr.manage');

        if (! in_array($kind, HrUnit::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => ['Unknown kind.']]);
        }

        $name = trim((string) ($data['name'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $description = $description === '' ? null : $description;
        $active = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : null;

        $errors = [];
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = ['Enter a name (max 120 characters).'];
        }
        if ($description !== null && mb_strlen($description) > 500) {
            $errors['description'] = ['Description must be 500 characters or fewer.'];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $label = $kind === HrUnit::KIND_TEAM ? 'team' : 'territory';

        try {
            return DB::transaction(function () use ($actor, $kind, $unitId, $tenantId, $name, $description, $active, $label): HrUnit {
                $unit = null;
                if ($unitId !== null) {
                    // Explicit tenant predicate on top of the global scope: another tenant's id (or kind) is a 404.
                    $unit = HrUnit::query()->where('tenant_id', $tenantId)->where('kind', $kind)->lockForUpdate()->findOrFail($unitId);
                }

                $duplicate = HrUnit::query()
                    ->where('tenant_id', $tenantId)
                    ->where('kind', $kind)
                    ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
                    ->when($unit !== null, fn ($q) => $q->where('id', '<>', $unit->id))
                    ->exists();

                if ($duplicate) {
                    throw ValidationException::withMessages(['name' => ["A {$label} with this name already exists."]]);
                }

                $unit ??= new HrUnit();
                $values = [
                    'name' => $name,
                    'description' => $description,
                    'is_active' => $active ?? ($unit->exists ? (bool) $unit->is_active : true),
                ];

                if (! $unit->exists) {
                    $values += ['tenant_id' => $tenantId, 'kind' => $kind, 'created_by' => $actor->id];
                }

                $unit->forceFill($values)->save();

                return $unit;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => ["A {$label} with this name already exists."]]);
        }
    }
}

<?php

namespace App\Domain\Purchasing\Actions;

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/** 11.2a: creates or edits a supplier. Names are unique per tenant, case-insensitively. Uses inventory.manage. */
final class SaveSupplierAction
{
    /** @param array<string, mixed> $data */
    public function execute(User $actor, ?int $supplierId, array $data): Supplier
    {
        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);
        Gate::forUser($actor)->authorize('inventory.manage');

        $clean = static function (mixed $v): ?string {
            $v = trim((string) ($v ?? ''));

            return $v === '' ? null : $v;
        };

        $name = trim((string) ($data['name'] ?? ''));
        $email = $clean($data['email'] ?? null);
        $phone = $clean($data['phone'] ?? null);
        $notes = $clean($data['notes'] ?? null);
        $active = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true;

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
        if ($notes !== null && mb_strlen($notes) > 2000) {
            $errors['notes'] = ['Notes must be 2000 characters or fewer.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        try {
            return DB::transaction(function () use ($actor, $supplierId, $tenantId, $name, $email, $phone, $notes, $active): Supplier {
                $supplier = null;
                if ($supplierId !== null) {
                    // Explicit tenant predicate on top of the global scope: another tenant's id is a 404.
                    $supplier = Supplier::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($supplierId);
                }

                $duplicate = Supplier::query()
                    ->where('tenant_id', $tenantId)
                    ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
                    ->when($supplier !== null, fn ($q) => $q->where('id', '<>', $supplier->id))
                    ->exists();

                if ($duplicate) {
                    throw ValidationException::withMessages(['name' => ['A supplier with this name already exists.']]);
                }

                $supplier ??= new Supplier();
                $values = ['name' => $name, 'email' => $email, 'phone' => $phone, 'notes' => $notes, 'is_active' => $active];

                if (! $supplier->exists) {
                    $values += ['tenant_id' => $tenantId, 'created_by' => $actor->id];
                }

                $supplier->forceFill($values)->save();

                return $supplier;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => ['A supplier with this name already exists.']]);
        }
    }
}

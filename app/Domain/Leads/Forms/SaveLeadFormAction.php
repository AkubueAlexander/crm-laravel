<?php

namespace App\Domain\Leads\Forms;

use App\Models\LeadForm;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/** 9.8a: create and update share one write path. Tenant, uuid and creator come from the server, never the body (1.3). */
final class SaveLeadFormAction
{
    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): LeadForm
    {
        $tenantId = $this->authorize($actor);
        $this->validate($data, $tenantId, null);

        try {
            // Nested transaction = savepoint: a lost race on the unique index never poisons an outer transaction.
            return DB::transaction(function () use ($actor, $data, $tenantId): LeadForm {
                $form = new LeadForm();
                $form->forceFill(array_merge($this->definition($data), [
                    'tenant_id' => $tenantId,
                    'uuid' => (string) Str::uuid(),
                    'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
                    'created_by' => $actor->id,
                ]))->save();

                return $form;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicateName();
        }
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, int $formId, array $data): LeadForm
    {
        $tenantId = $this->authorize($actor);
        $this->validate($data, $tenantId, $formId);

        try {
            return DB::transaction(function () use ($formId, $data, $tenantId): LeadForm {
                // Explicit tenant predicate on top of the global scope: another tenant's id is a 404.
                $form = LeadForm::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($formId);

                $changes = $this->definition($data);

                if (array_key_exists('is_active', $data)) {
                    $changes['is_active'] = (bool) $data['is_active'];
                }

                $form->forceFill($changes)->save();

                return $form;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicateName();
        }
    }

    private function authorize(User $actor): int
    {
        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        // 2.4: server-side re-validation, independent of the request layer.
        Gate::forUser($actor)->authorize('marketing.manage');

        return $tenantId;
    }

    /** @param array<string, mixed> $data */
    private function validate(array $data, int $tenantId, ?int $ignoreId): void
    {
        $errors = [];

        $name = $data['name'] ?? null;

        if (! is_string($name) || trim($name) === '' || mb_strlen(trim($name)) > LeadFormDsl::MAX_NAME) {
            $errors['name'] = 'Enter a name of up to '.LeadFormDsl::MAX_NAME.' characters.';
        } elseif ($this->nameTaken($tenantId, trim($name), $ignoreId)) {
            // Friendly message; the unique index on (tenant_id, lower(name)) is the real guard.
            $errors['name'] = 'A form with this name already exists.';
        }

        foreach (['source' => LeadFormDsl::MAX_SOURCE, 'success_message' => LeadFormDsl::MAX_MESSAGE] as $key => $max) {
            $value = $data[$key] ?? null;

            if ($value !== null && (! is_string($value) || mb_strlen($value) > $max)) {
                $errors[$key] = "Keep this to {$max} characters or fewer.";
            }
        }

        if (array_key_exists('is_active', $data) && ! is_bool($data['is_active'])) {
            $errors['is_active'] = 'Active must be true or false.';
        }

        $errors += LeadFormDsl::errors($data['fields'] ?? null);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function nameTaken(int $tenantId, string $name, ?int $ignoreId): bool
    {
        return LeadForm::query()
            ->where('tenant_id', $tenantId)
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function definition(array $data): array
    {
        $source = isset($data['source']) ? trim((string) $data['source']) : '';
        $message = isset($data['success_message']) ? trim((string) $data['success_message']) : '';

        return [
            'name' => trim((string) $data['name']),
            'source' => $source === '' ? null : $source,
            'success_message' => $message === '' ? null : $message,
            'fields' => LeadFormDsl::normalize($data['fields']),
        ];
    }

    private function duplicateName(): ValidationException
    {
        return ValidationException::withMessages(['name' => ['A form with this name already exists.']]);
    }
}
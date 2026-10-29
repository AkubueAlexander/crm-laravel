<?php

namespace App\Domain\Accounting\Actions;

use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * 12.0a: creates or edits a chart-of-accounts entry. The code is stored upper-case and unique per tenant. Once an
 * account has postings its code and type are frozen (reports and history depend on them); the name and the active
 * flag can always change. Needs accounting.manage.
 */
final class SaveLedgerAccountAction
{
    /** @param array<string, mixed> $data */
    public function execute(User $actor, ?int $accountId, array $data): LedgerAccount
    {
        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);
        Gate::forUser($actor)->authorize('accounting.manage');

        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        $name = trim((string) ($data['name'] ?? ''));
        $type = trim((string) ($data['type'] ?? ''));
        $active = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true;

        $errors = [];

        if (! preg_match('/^[A-Z0-9][A-Z0-9.-]{0,15}$/', $code)) {
            $errors['code'] = ['Code must be 1-16 letters, digits, dots or dashes.'];
        }

        if ($name === '' || mb_strlen($name) > 160) {
            $errors['name'] = ['Enter a name (max 160 characters).'];
        }

        if (! in_array($type, LedgerAccount::TYPES, true)) {
            $errors['type'] = ['Choose an account type.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        try {
            return DB::transaction(function () use ($actor, $accountId, $tenantId, $code, $name, $type, $active): LedgerAccount {
                $account = null;
                if ($accountId !== null) {
                    // Explicit tenant predicate on top of the global scope: another tenant's id is a 404.
                    $account = LedgerAccount::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($accountId);
                }

                $duplicate = LedgerAccount::query()
                    ->where('tenant_id', $tenantId)
                    ->where('code', $code)
                    ->when($account !== null, fn ($q) => $q->where('id', '<>', $account->id))
                    ->exists();

                if ($duplicate) {
                    throw ValidationException::withMessages(['code' => ['This account code already exists.']]);
                }

                if ($account !== null && ($code !== $account->code || $type !== $account->type)) {
                    $used = JournalLine::query()->where('tenant_id', $tenantId)->where('ledger_account_id', $account->id)->exists();
                    if ($used) {
                        throw ValidationException::withMessages([
                            $code !== $account->code ? 'code' : 'type' => ['The code and type cannot change once the account has postings.'],
                        ]);
                    }
                }

                $account ??= new LedgerAccount();
                $values = ['code' => $code, 'name' => $name, 'type' => $type, 'is_active' => $active];

                if (! $account->exists) {
                    $values += ['tenant_id' => $tenantId, 'created_by' => $actor->id];
                }

                $account->forceFill($values)->save();

                return $account;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => ['This account code already exists.']]);
        }
    }
}

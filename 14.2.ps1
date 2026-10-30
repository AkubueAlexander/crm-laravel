$ErrorActionPreference = 'Stop'
$repo = 'C:\wamp64\www\crm-erp'
if (-not (Test-Path (Join-Path $repo 'artisan'))) { throw 'artisan not found' }
Set-Location $repo

$pipe = Join-Path $repo 'frontend\src\features\settings\components\PipelineSettingsForm.tsx'
$quote = Join-Path $repo 'frontend\src\features\settings\components\QuoteSettingsForm.tsx'
$brand = Join-Path $repo 'frontend\src\features\settings\components\BrandingSettingsForm.tsx'
$expo = Join-Path $repo 'frontend\src\features\contacts\components\ContactExportDialog.tsx'
foreach ($p in @($pipe, $quote, $brand, $expo, (Join-Path $repo 'frontend\src\shared\lib\apiClient.ts'), (Join-Path $repo 'tests\Feature'))) {
    if (-not (Test-Path $p)) { throw "Missing prerequisite: $p" }
}

$report = New-Object System.Collections.ArrayList
function Add-Report([string]$t) { [void]$report.Add($t) }
function Tail([string]$text, [int]$n) {
    $lines = $text -split "`r?`n"
    if ($lines.Count -le $n) { return ($lines -join "`n") }
    return (($lines | Select-Object -Last $n) -join "`n")
}
function Finish {
    $out = $report -join "`n"
    Write-Host $out
    Set-Clipboard -Value $out
    Write-Host 'Report copied'
    Read-Host 'Press Enter to close'
}
function Write-NoBom([string]$Path, [string]$Content) {
    if (-not [System.IO.Path]::IsPathRooted($Path)) { $Path = Join-Path $repo $Path }
    $dir = Split-Path $Path -Parent
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $c = $Content.Replace("`r`n", "`n").TrimEnd("`n") + "`n"
    [System.IO.File]::WriteAllText($Path, $c, (New-Object System.Text.UTF8Encoding($false)))
}
function Read-Text([string]$p) { return [System.IO.File]::ReadAllText($p, [System.Text.Encoding]::UTF8) }
function Count-Sub([string]$text, [string]$sub) {
    $n = 0; $i = 0
    while (($i = $text.IndexOf($sub, $i, [StringComparison]::Ordinal)) -ge 0) { $n++; $i += $sub.Length }
    return $n
}
function Patch-Once([string]$Path, [string]$Anchor, [string]$Replacement, [string]$Skip) {
    $raw = Read-Text $Path
    $crlf = $raw.Contains("`r`n")
    $t = $raw.Replace("`r`n", "`n")
    if ($Skip -and $t.Contains($Skip)) { return 'skipped' }
    if ((Count-Sub $t $Anchor) -ne 1) { throw "Anchor not unique in ${Path}: $Anchor" }
    $t = $t.Replace($Anchor, $Replacement)
    if ($crlf) { $t = $t.Replace("`n", "`r`n") }
    [System.IO.File]::WriteAllText($Path, $t, (New-Object System.Text.UTF8Encoding($false)))
    return 'patched'
}

# ------------------------------------------------------------ new files
$helper = @'
import type { FieldValues, Path, UseFormReturn } from 'react-hook-form';
import { isApiError } from '@/shared/lib/apiClient';

// 14.2: the Laravel Form Request / Action is the validation boundary; Zod is only fast UX feedback.
// When the server rejects a form with 422 { message, errors, code }, map each error onto its field so a
// rule that exists only server-side still shows under the right input. Returns true when at least one
// field error was set. Non-validation failures (403, 409, 500, network) are not field errors: the form
// keeps showing them as a banner via apiErrorMessage.
export function applyServerErrors<T extends FieldValues>(form: UseFormReturn<T>, error: unknown): boolean {
    if (!isApiError(error)) return false;

    const errors = error.response?.data.errors;
    if (!errors) return false;

    let mapped = false;
    for (const [field, messages] of Object.entries(errors)) {
        const message = Array.isArray(messages) ? messages[0] : undefined;
        if (typeof message !== 'string') continue;

        form.setError(field as Path<T>, { type: 'server', message });
        mapped = true;
    }

    return mapped;
}
'@

$doc = @'
# 14.2 Validation layers

Validation exists in two layers on purpose.

- **Backend (the boundary).** Form Requests validate shape; the Action re-validates and authorizes
  (`Gate::forUser($actor)->authorize`). Failures leave the API as 422 `{ message, errors, code: "validation_failed" }`
  (403 as `http_403`, and so on, see 14.3). The backend rule is the one that is load-bearing.
- **Frontend (UX only).** React Hook Form + one Zod schema per form: fast feedback, no round trip for obviously invalid
  input. A Zod schema is never a security control, and a hidden or disabled control is not either.

## Rules for every mutating form

1. One Zod schema per form (`features/<domain>/schemas`), mirroring the server rules that are cheap to express
   (required, max length, numeric, enum, date order). Do not invent rules the server does not have.
2. Rules that exist only on the server (uniqueness, state checks, permission, tenant ownership, cross-record rules)
   must surface in the form: call `applyServerErrors(form, error)` from `@/shared/lib/applyServerErrors` in the
   mutation's `onError` (or in the `catch` of `mutateAsync`). It maps `errors.<field>` onto the matching RHF field,
   including dotted paths such as `stages.won`.
3. Keep a banner (`apiErrorMessage(mutation.error)`) for everything that is not a field error (403, 409, 5xx, network).
4. When you add or change a rule in a Form Request or Action, update the form's Zod schema in the same change; when you
   add a Zod rule, confirm the server enforces the same rule (the server may be stricter, never looser).
5. A backend feature test asserts the 422 shape for each form's endpoint (`tests/Feature/Validation`).

## Status (14.2)

Wired to `applyServerErrors`: pipeline settings, quote settings, branding settings, contact export.
Older forms map errors with an inline `setError` loop (accounts, contacts, leads, and others); that is equivalent and
may be migrated to the helper when the file is next touched.

Not form-level (nothing to map): `LeavePage` filter form, `AutomationStepRow` and `ConditionRow` (rows inside a parent
form). `CampaignLifecycleDialog` receives only an error string from its parent and shows the server message in its banner.
'@

$test = @'
<?php

use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

function vlayActor(string $role): User
{
    $tenant = Tenant::factory()->create();
    TenantContext::set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
    RoleCatalog::provision($tenant);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

// 14.2: the backend is the validation boundary; the SPA maps errors.<field> onto form fields, so the keys matter.
it('rejects an invalid quote settings payload with a field-keyed 422', function () {
    config(['cache.limiter' => 'array', 'logging.default' => 'null']);
    Sanctum::actingAs(vlayActor('admin'));

    $this->putJson('/api/v1/settings/quotes', ['approval_threshold' => 'not-a-number'])
        ->assertStatus(422)
        ->assertJson(['code' => 'validation_failed'])
        ->assertJsonStructure(['message', 'errors', 'code'])
        ->assertJsonValidationErrors(['approval_threshold']);
});

it('forbids quote settings changes for a viewer with the structured 403 shape', function () {
    config(['cache.limiter' => 'array', 'logging.default' => 'null']);
    Sanctum::actingAs(vlayActor('viewer'));

    $this->putJson('/api/v1/settings/quotes', ['approval_threshold' => '100'])
        ->assertStatus(403)
        ->assertJson(['code' => 'http_403', 'errors' => []]);
});
'@

# ------------------------------------------------------------ patches (preflight first)
$importAfterApiMsg = "import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';`nimport { applyServerErrors } from '@/shared/lib/applyServerErrors';"
$importAfterZod = "import { zodResolver } from '@hookform/resolvers/zod';`nimport { applyServerErrors } from '@/shared/lib/applyServerErrors';"
$onErrorSkip = 'onError: (err) => applyServerErrors(form, err)'
$importSkip = 'import { applyServerErrors }'

$patches = New-Object System.Collections.ArrayList
function Add-Patch([string]$Path, [string]$Anchor, [string]$Replacement, [string]$Skip) {
    [void]$patches.Add(@{ Path = $Path; Anchor = $Anchor; Replacement = $Replacement; Skip = $Skip })
}

Add-Patch $pipe "import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';" $importAfterApiMsg $importSkip
Add-Patch $pipe 'update.mutate(pipelinePayload(values), {' "update.mutate(pipelinePayload(values), {`n            onError: (err) => applyServerErrors(form, err)," $onErrorSkip
Add-Patch $quote "import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';" $importAfterApiMsg $importSkip
Add-Patch $quote 'update.mutate(quoteSettingsPayload(values), {' "update.mutate(quoteSettingsPayload(values), {`n            onError: (err) => applyServerErrors(form, err)," $onErrorSkip
Add-Patch $brand "import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';" $importAfterApiMsg $importSkip
Add-Patch $brand 'update.mutate(values, {' "update.mutate(values, {`n            onError: (err) => applyServerErrors(form, err)," $onErrorSkip
Add-Patch $expo "import { zodResolver } from '@hookform/resolvers/zod';" $importAfterZod $importSkip
Add-Patch $expo '{ onSuccess: (created) => onStarted(created.id) },' '{ onSuccess: (created) => onStarted(created.id), onError: (err) => applyServerErrors(form, err) },' $onErrorSkip

foreach ($p in $patches) {
    $t = (Read-Text $p.Path).Replace("`r`n", "`n")
    if ($t.Contains($p.Skip)) { continue }
    if ((Count-Sub $t $p.Anchor) -ne 1) { throw "PREFLIGHT: anchor not unique in $($p.Path): $($p.Anchor)" }
}

# ------------------------------------------------------------ write
Write-NoBom 'frontend\src\shared\lib\applyServerErrors.ts' $helper
Write-NoBom 'docs\14.2-validation-layers.md' $doc
Write-NoBom 'tests\Feature\Validation\ValidationBoundaryTest.php' $test
foreach ($p in $patches) {
    Add-Report ('patch ' + (Split-Path $p.Path -Leaf) + ': ' + (Patch-Once $p.Path $p.Anchor $p.Replacement $p.Skip))
}
Add-Report 'Wrote: shared/lib/applyServerErrors.ts, docs/14.2-validation-layers.md, tests/Feature/Validation/ValidationBoundaryTest.php; patched Pipeline/Quote/Branding settings forms and ContactExportDialog'

# ------------------------------------------------------------ gates
Set-Location (Join-Path $repo 'frontend')
$ErrorActionPreference = 'Continue'
$tsc = npx.cmd tsc --noEmit 2>&1 | Out-String
$tscCode = $LASTEXITCODE
$ErrorActionPreference = 'Stop'
Set-Location $repo
if ($tscCode -eq 0) { Add-Report 'tsc: clean' } else { Add-Report ("tsc FAILED:`n" + (Tail $tsc 60)) }

$ErrorActionPreference = 'Continue'
php artisan config:clear 2>&1 | Out-Null
$t1 = php artisan test tests/Feature/Validation 2>&1 | Out-String
$t1Code = $LASTEXITCODE
$ErrorActionPreference = 'Stop'
if ($t1Code -eq 0) { Add-Report ("Validation tests OK:`n" + (Tail $t1 6)) }
else { Add-Report ("Validation tests FAILED:`n" + (Tail $t1 60)) }

Add-Report 'NOT TOUCHED (referenced, not shown): the quote-settings Form Request/Action and their rules (test assumes payload key approval_threshold), CampaignLifecycleDialog parent, AccountForm and other inline-loop forms, pipeline/branding endpoints (no backend test yet: payload shapes not shown)'
Finish

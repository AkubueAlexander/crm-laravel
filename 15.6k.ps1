$ErrorActionPreference = 'Stop'
$repo = $PSScriptRoot
$enc = New-Object System.Text.UTF8Encoding($false)

function Write-NoBom([string]$Rel, [string]$Content) {
    $path = Join-Path $repo $Rel
    $dir = Split-Path $path -Parent
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    [System.IO.File]::WriteAllText($path, $Content, $enc)
    Write-Host "wrote $Rel"
}

# ---------------------------------------------------------------- E2eLeadSeeder
Write-NoBom 'database\seeders\E2eLeadSeeder.php' @'
<?php

namespace Database\Seeders;

use App\Domain\Tenant\TenantContext;
use App\Models\Lead;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * 15.6: one deterministic, convertible lead for the Playwright lead-conversion spec (8a.2).
 * `status` is left to the column default so a new lead starts in whatever state the app treats as new.
 */
class E2eLeadSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'acme')->firstOrFail();
        TenantContext::set($tenant);

        Lead::create([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada.lovelace@e2e.test',
            'company' => 'E2E Analytical Ltd',
            'source' => 'e2e',
        ]);

        TenantContext::clear();

        $this->command?->info('E2E lead seeded.');
    }
}
'@

# ---------------------------------------------------------------- hook into e2e:prepare
$cmd = Join-Path $repo 'app\Console\Commands\E2ePrepare.php'
$src = [System.IO.File]::ReadAllText($cmd)
if ($src -notmatch 'E2eLeadSeeder') {
    $needle = "`$this->call('db:seed', ['--class' => 'Database\\Seeders\\E2eDealSeeder', '--force' => true]);"
    if (-not $src.Contains($needle)) { throw 'E2eDealSeeder line not found in E2ePrepare.php - edit by hand.' }
    $nl = if ($src.Contains("`r`n")) { "`r`n" } else { "`n" }
    $add = $needle + $nl + "        `$this->call('db:seed', ['--class' => 'Database\\Seeders\\E2eLeadSeeder', '--force' => true]);"
    [System.IO.File]::WriteAllText($cmd, $src.Replace($needle, $add), $enc)
    Write-Host 'patched app\Console\Commands\E2ePrepare.php'
} else {
    Write-Host 'E2ePrepare.php already seeds the lead'
}

# ---------------------------------------------------------------- leads spec
Write-NoBom 'frontend\e2e\leads.e2e.ts' @'
import { expect, test } from '@playwright/test';
import { cards, column, openBoard } from './support/board';
import { signIn } from './support/session';

test.describe('Lead -> Deal conversion (8a.2)', () => {
    test('converting a lead marks it converted and puts a new deal on the board', async ({ page }) => {
        await signIn(page);
        await page.goto('/leads');

        const row = page.getByRole('row').filter({ hasText: 'Lovelace' });
        await expect(row).toHaveCount(1);
        await row.getByRole('button', { name: 'Convert' }).click();

        const dialog = page.getByRole('dialog', { name: 'Convert lead' });
        await expect(dialog).toBeVisible();
        await dialog.getByLabel('Deal name').fill('E2E Converted Deal');
        await dialog.getByLabel('Amount').fill('2500');

        const converted = page.waitForResponse(
            (r) => /\/leads\/[^/]+\/convert/.test(r.url()) && r.request().method() === 'POST',
        );
        await dialog.getByRole('button', { name: 'Convert lead' }).click();
        const res = await converted;
        expect(res.ok(), `${res.status()} ${await res.text()}`).toBeTruthy();

        await expect(dialog).toContainText('was converted');
        await dialog.getByRole('button', { name: 'Done' }).click();
        await expect(dialog).toBeHidden();

        // The leads list was invalidated: the row is now converted and no longer offers Convert.
        await expect(row).toContainText('converted');
        await expect(row.getByRole('button', { name: 'Convert' })).toHaveCount(0);

        // The new deal exists on the board, in the starting stage, with the amount we entered.
        await openBoard(page);
        const deal = cards(column(page, 'lead')).filter({ hasText: 'E2E Converted Deal' });
        await expect(deal).toHaveCount(1);
        await expect(deal).toContainText('2,500');
    });
});
'@

Write-Host ''
Write-Host 'NOT TOUCHED: leads migration (status default assumed valid), contacts/accounts pages (conversion side effects not asserted there), ci.yml.'

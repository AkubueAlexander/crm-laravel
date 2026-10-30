$ErrorActionPreference = 'Stop'
$repo = $PSScriptRoot
$enc = New-Object System.Text.UTF8Encoding($false)
$path = Join-Path $repo 'app\Domain\Users\RoleCatalog.php'
$src = [System.IO.File]::ReadAllText($path)

if ($src.Contains("'deals.transition'")) {
    Write-Host 'skip (already patched) RoleCatalog.php'
} else {
    # 1) ALL_PERMISSIONS: after deals.view, before forecast.view (same order as DevTenantSeeder::PERMISSIONS).
    if (-not $src.Contains("'forecast.view',")) { throw "forecast.view not found - edit by hand." }
    $nl = if ($src.Contains("`r`n")) { "`r`n" } else { "`n" }
    $src = $src.Replace("'forecast.view',", "'deals.transition',$nl        'forecast.view',")

    # 2) sales role: reps move deals through the pipeline.
    $rx = [regex]"'deals\.view',(\s*)\],"
    if (-not $rx.IsMatch($src)) { throw 'sales deals.view line not found - edit by hand.' }
    $src = $rx.Replace($src, "'deals.view', 'deals.transition',`$1],", 1)

    [System.IO.File]::WriteAllText($path, $src, $enc)
    Write-Host 'patched app\Domain\Users\RoleCatalog.php'
}

Write-Host ''
Write-Host 'NOT TOUCHED: any Pest test asserting exact role permission lists (run php artisan test), your dev database (re-run php artisan db:seed --class=DevTenantSeeder), ci.yml.'

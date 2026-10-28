$ErrorActionPreference = 'Stop'
$repo = 'C:\wamp64\www\crm-erp'
if (-not (Test-Path (Join-Path $repo 'artisan'))) { throw "artisan not found in $repo" }
Set-Location $repo

$utf8 = New-Object System.Text.UTF8Encoding($false)
$report = New-Object System.Collections.Generic.List[string]
function Say([string]$m) { Write-Host $m; $script:report.Add($m) }

function Count-Sub([string]$Text, [string]$Sub) {
    $n = 0; $i = 0
    while (($i = $Text.IndexOf($Sub, $i, [System.StringComparison]::Ordinal)) -ge 0) { $n++; $i += $Sub.Length }
    return $n
}

$path = Join-Path $repo 'tests\Feature\Marketing\CampaignSendTest.php'
if (-not (Test-Path $path)) { throw "Missing: $path" }

# Flipping the LAST base64 char can decode to the same bytes (padding bits), so the "tampered" token is still valid.
$anchor = '$tampered = substr($good, 0, -1).(substr($good, -1) === ''A'' ? ''B'' : ''A'');'
$replacement = '$tampered = substr($good, 0, 5).(substr($good, 5, 1) === ''A'' ? ''B'' : ''A'').substr($good, 6);'

$raw = [System.IO.File]::ReadAllText($path, $utf8)
$crlf = $raw.Contains("`r`n")
$t = $raw.Replace("`r`n", "`n")
if ($t.Contains($replacement)) {
    Say 'skipped : already patched'
} else {
    if ((Count-Sub $t $anchor) -ne 1) { throw "Anchor not exactly once in CampaignSendTest.php" }
    $t = $t.Replace($anchor, $replacement)
    if ($crlf) { $t = $t.Replace("`n", "`r`n") }
    [System.IO.File]::WriteAllText($path, $t, $utf8)
    Say 'patched : tests\Feature\Marketing\CampaignSendTest.php'
}

$ErrorActionPreference = 'Continue'
foreach ($i in 1..3) {
    Say "Run $i of CampaignSendTest..."
    $out = & php artisan test --no-ansi tests/Feature/Marketing/CampaignSendTest.php 2>&1 | Out-String
    Say (($out -split "`r?`n" | Select-Object -Last 6) -join "`n")
}
$ErrorActionPreference = 'Stop'

Set-Clipboard -Value ($report -join "`r`n")
Write-Host 'Report copied'
Read-Host 'Press Enter to close'
Write-Host 'NOT TOUCHED (referenced, not shown): the rest of CampaignSendTest.php and every other file.'

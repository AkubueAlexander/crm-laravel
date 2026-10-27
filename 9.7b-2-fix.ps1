$ErrorActionPreference = 'Stop'
$repo = 'C:\wamp64\www\crm-erp'
if (-not (Test-Path (Join-Path $repo 'artisan'))) { throw 'artisan not found: wrong repo path' }
Set-Location $repo

function Count-Sub([string]$Text, [string]$Sub) {
    $n = 0; $i = 0
    while (($i = $Text.IndexOf($Sub, $i, [System.StringComparison]::Ordinal)) -ge 0) { $n++; $i += $Sub.Length }
    return $n
}

function Patch-Once([string]$Path, [string]$Anchor, [string]$Replacement, [string]$Skip) {
    if (-not [System.IO.Path]::IsPathRooted($Path)) { $Path = Join-Path $repo $Path }
    $raw = [System.IO.File]::ReadAllText($Path)
    $crlf = $raw.Contains("`r`n")
    $text = $raw -replace "`r`n", "`n"
    if ($text.Contains($Skip)) { return 'skipped' }
    $a = $Anchor -replace "`r`n", "`n"
    $r = $Replacement -replace "`r`n", "`n"
    if ((Count-Sub $text $a) -ne 1) { throw "Anchor not exactly once in $Path : $Anchor" }
    $idx = $text.IndexOf($a, [System.StringComparison]::Ordinal)
    $text = $text.Substring(0, $idx) + $r + $text.Substring($idx + $a.Length)
    if ($crlf) { $text = $text -replace "`n", "`r`n" }
    $enc = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($Path, $text, $enc)
    return 'patched'
}

$api = 'tests\Feature\Marketing\SegmentApiTest.php'
$dsl = 'tests\Feature\Marketing\SegmentDslTest.php'
foreach ($p in @($api, $dsl)) { if (-not (Test-Path (Join-Path $repo $p))) { throw "Missing $p" } }

$anchor = "->toBe(['name', 'email', 'job_title', 'phone', 'account', 'created_at']);"
$replacement = "->toBe(['name', 'email', 'job_title', 'phone', 'account', 'created_at', 'lead_score']);"
$skip = "'created_at', 'lead_score']"

# PREFLIGHT
foreach ($p in @($api, $dsl)) {
    $t = ([System.IO.File]::ReadAllText((Join-Path $repo $p))) -replace "`r`n", "`n"
    if (-not $t.Contains($skip) -and (Count-Sub $t $anchor) -ne 1) { throw "PREFLIGHT: anchor not exactly once in $p" }
}

foreach ($p in @($api, $dsl)) { Write-Host "${p}: $(Patch-Once $p $anchor $replacement $skip)" }

$ErrorActionPreference = 'Continue'
$test = (& php artisan test --no-ansi tests/Feature/Marketing/SegmentApiTest.php tests/Feature/Marketing/SegmentDslTest.php tests/Feature/Marketing/SegmentLeadScoreTest.php 2>&1 | Out-String)
$code = $LASTEXITCODE
$ErrorActionPreference = 'Stop'

$report = New-Object System.Collections.Generic.List[string]
function Say([string]$s) { $report.Add($s); Write-Host $s }
Say "9.7b-2-fix done. php artisan test exit code: $code"
if ($code -ne 0) { Say $test } else { Say ((($test -split "`r?`n") | Select-Object -Last 6) -join "`n") }
Set-Clipboard -Value ($report -join "`r`n")
Write-Host 'Report copied'
Read-Host 'Press Enter to close'
Write-Host 'NOT TOUCHED (referenced, not shown): the rest of SegmentApiTest.php and SegmentDslTest.php (only the one field-list line in each was patched).'

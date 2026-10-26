$ErrorActionPreference = 'Continue'

# 9.4a: register routes\campaign_lifecycle.php in routes\api.php, then verify. Run from the repo root.
# Self-contained and verbose: every step prints, and the whole report is copied to the clipboard.

$report = New-Object System.Collections.Generic.List[string]
function Say([string]$s) { $report.Add($s); Write-Host $s }

Say '=== fix-routes-9.4a started'

if (-not (Test-Path 'artisan')) { Say 'ERROR: run this from the repo root (artisan not found).'; Set-Clipboard -Value ($report -join "`r`n"); return }
if (-not (Test-Path 'routes\campaign_lifecycle.php')) { Say 'ERROR: routes\campaign_lifecycle.php is missing. Re-run the 9.4a script.'; Set-Clipboard -Value ($report -join "`r`n"); return }

$apiPath = Join-Path (Get-Location).Path 'routes\api.php'
$text = [System.IO.File]::ReadAllText($apiPath)
Say ("routes\api.php length: {0} chars" -f $text.Length)

if ($text -match 'campaign_lifecycle\.php') {
    Say 'routes\api.php already contains campaign_lifecycle.php (no patch needed)'
} else {
    $eol = if ($text.Contains("`r`n")) { "`r`n" } else { "`n" }
    $lines = $text -split "`r?`n"

    $hits = @()
    for ($i = 0; $i -lt $lines.Count; $i++) {
        $t = $lines[$i].Trim()
        if ($t -match 'email_templates\.php' -and $t -match 'require' -and $t -notmatch '^(//|#|/\*|\*)') { $hits += $i }
    }
    Say ("real require lines for email_templates.php: {0}" -f $hits.Count)

    if ($hits.Count -ne 1) {
        for ($i = 0; $i -lt $lines.Count; $i++) {
            if ($lines[$i] -match 'email_templates') { Say ("{0}: {1}" -f ($i + 1), $lines[$i]) }
        }
        Say 'ERROR: could not find exactly one anchor. Not patched.'
        Set-Clipboard -Value ($report -join "`r`n"); return
    }

    $at = $hits[0]
    $newLine = $lines[$at] -replace 'email_templates', 'campaign_lifecycle'
    $out = New-Object System.Collections.Generic.List[string]
    for ($i = 0; $i -lt $lines.Count; $i++) {
        $out.Add($lines[$i])
        if ($i -eq $at) { $out.Add($newLine) }
    }
    [System.IO.File]::WriteAllText($apiPath, ($out -join $eol), (New-Object System.Text.UTF8Encoding($false)))
    Say ("PATCHED routes\api.php after line {0}: {1}" -f ($at + 1), $newLine.Trim())
}

Say ''
Say '=== routes\api.php now'
Select-String -Path 'routes\api.php' -Pattern 'campaign_lifecycle|email_templates' | ForEach-Object { Say ("{0}: {1}" -f $_.LineNumber, $_.Line) }

Say ''
Say '=== php artisan route:clear'
Say ((& php artisan route:clear 2>&1 | Out-String).TrimEnd())

Say ''
Say '=== route:list --path=campaigns'
Say ((& php artisan route:list --path=campaigns 2>&1 | Out-String).TrimEnd())

Say ''
Say '=== tests (CampaignTransition), last 25 lines'
$t = & php artisan test --filter=CampaignTransition 2>&1 | Out-String
$tail = ($t -split "`r?`n") | Select-Object -Last 25
foreach ($l in $tail) { Say $l }

Set-Clipboard -Value ($report -join "`r`n")
Write-Host ''
Write-Host 'Report copied to clipboard. Paste it into the chat if anything is still red.' -ForegroundColor Green

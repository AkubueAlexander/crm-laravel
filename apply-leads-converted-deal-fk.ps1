Write-Host "Running apply-leads-converted-deal-fk.ps1 (v1 - ASCII only, CRLF, BOM)" -ForegroundColor Cyan

$root = "C:\wamp64\www\crm-erp"

function Write-NoBom {
    param(
        [Parameter(Mandatory)][string]$Path,
        [Parameter(Mandatory)][string]$Content
    )
    $full = Join-Path $root $Path
    $dir = Split-Path $full -Parent
    if (-not (Test-Path $dir)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
    }
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($full, $Content, $utf8NoBom)
    Write-Host "Wrote $Path"
}

# ---------------------------------------------------------------------------
# database/migrations/..._add_foreign_key_to_leads_converted_deal_id.php (NEW)
#   Adds the FK the handover flagged as missing: leads.converted_deal_id was a
#   bare unsignedBigInteger with no FK because the Deal model hadn't been
#   shown yet. Purely additive - assumes the column already exists (per
#   handover) and that this migration runs after both the leads and deals
#   table migrations, which a same-day timestamp after "today" guarantees.
# ---------------------------------------------------------------------------
Write-NoBom -Path "database\migrations\2026_09_22_150000_add_foreign_key_to_leads_converted_deal_id.php" -Content @'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->foreign('converted_deal_id')
                ->references('id')->on('deals')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['converted_deal_id']);
        });
    }
};
'@

Write-Host ""
Write-Host "Done. 1 file written." -ForegroundColor Green
Write-Host "Run 'php artisan migrate' after this, then confirm 'php artisan migrate:status' shows it applied." -ForegroundColor Yellow

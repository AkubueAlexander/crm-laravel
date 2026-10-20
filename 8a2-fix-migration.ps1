param(
    [string]$ProjectRoot = "."
)

function Write-NoBom {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][string]$Content
    )
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($Path, $Content, $utf8NoBom)
    Write-Host "Wrote $Path"
}

$Root = (Resolve-Path $ProjectRoot).Path

# Find the migration file we wrote last turn (by name pattern, since the
# timestamp in the filename will differ run to run) and overwrite it in place
# rather than creating a second migration for the same change.
$existing = Get-ChildItem -Path (Join-Path $Root "database/migrations") -Filter "*_add_conversion_fields_to_leads_table.php" | Select-Object -First 1

if (-not $existing) {
    Write-Host "Could not find the add_conversion_fields_to_leads_table migration — nothing overwritten." -ForegroundColor Yellow
    Write-Host "Paste its current filename and I'll target it directly."
    exit 1
}

$migrationContent = @'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // Guarded per-column: converted_at already existed on this
            // install from earlier work, so this migration only adds
            // whatever's actually missing rather than assuming a clean slate.
            if (! Schema::hasColumn('leads', 'converted_at')) {
                $table->timestamp('converted_at')->nullable()->after('status');
            }

            if (! Schema::hasColumn('leads', 'contact_id')) {
                $table->foreignId('contact_id')->nullable()->after('converted_at')->constrained()->nullOnDelete();
            }

            if (! Schema::hasColumn('leads', 'deal_id')) {
                $table->foreignId('deal_id')->nullable()->after('contact_id')->constrained()->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            if (Schema::hasColumn('leads', 'deal_id')) {
                $table->dropConstrainedForeignId('deal_id');
            }

            if (Schema::hasColumn('leads', 'contact_id')) {
                $table->dropConstrainedForeignId('contact_id');
            }

            if (Schema::hasColumn('leads', 'converted_at')) {
                $table->dropColumn('converted_at');
            }
        });
    }
};
'@

Write-NoBom -Path $existing.FullName -Content $migrationContent
Write-Host ""
Write-Host "Overwrote $($existing.Name) with per-column guards." -ForegroundColor Green

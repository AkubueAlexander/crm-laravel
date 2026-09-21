# create-leads-backend.ps1
function Write-NoBom {
    param([string]$RelativePath, [string]$Content)
    $fullPath = Join-Path (Get-Location).Path $RelativePath
    $dir = Split-Path $fullPath -Parent
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($fullPath, $Content, $utf8NoBom)
    Write-Host "Wrote $RelativePath"
}

function Patch-Once {
    param([string]$Path, [string]$Anchor, [string]$Replacement, [string]$Marker)
    $fullPath = Join-Path (Get-Location).Path $Path
    $content = [System.IO.File]::ReadAllText($fullPath)
    if ($content.Contains($Marker)) {
        Write-Host "Skipped $Path (marker already present)"
        return
    }
    $count = ([regex]::Matches($content, [regex]::Escape($Anchor))).Count
    if ($count -ne 1) {
        throw "Anchor found $count times (expected 1) in $Path"
    }
    $newContent = $content.Replace($Anchor, $Replacement)
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($fullPath, $newContent, $utf8NoBom)
    Write-Host "Patched $Path"
}

Write-NoBom 'app\Domain\Leads\Actions\CreateLeadAction.php' @'
<?php

namespace App\Domain\Leads\Actions;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class CreateLeadAction
{
    /** @param array<string, mixed> $data Already validated by StoreLeadRequest. */
    public function execute(User $actor, array $data): Lead
    {
        Gate::forUser($actor)->authorize('leads.create');

        // tenant_id is stamped by BelongsToTenant from TenantContext, never from $data.
        return Lead::create($data);
    }
}
'@

Write-NoBom 'app\Domain\Leads\Actions\UpdateLeadAction.php' @'
<?php

namespace App\Domain\Leads\Actions;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class UpdateLeadAction
{
    /** @param array<string, mixed> $data Already validated by UpdateLeadRequest. */
    public function execute(User $actor, Lead $lead, array $data): Lead
    {
        Gate::forUser($actor)->authorize('leads.update');

        // "status" can never reach "converted" here: UpdateLeadRequest's
        // ASSIGNABLE_STATUSES excludes it. Only ConvertLeadAction sets it.
        $lead->update($data);

        return $lead;
    }
}
'@

Write-NoBom 'app\Domain\Leads\Actions\DeleteLeadAction.php' @'
<?php

namespace App\Domain\Leads\Actions;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class DeleteLeadAction
{
    public function execute(User $actor, Lead $lead): void
    {
        Gate::forUser($actor)->authorize('leads.delete');

        $lead->delete();
    }
}
'@

Write-NoBom 'app\Http\Controllers\Api\Leads\LeadController.php' @'
<?php

namespace App\Http\Controllers\Api\Leads;

use App\Domain\Leads\Actions\CreateLeadAction;
use App\Domain\Leads\Actions\DeleteLeadAction;
use App\Domain\Leads\Actions\UpdateLeadAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Leads\ListLeadsRequest;
use App\Http\Requests\Leads\StoreLeadRequest;
use App\Http\Requests\Leads\UpdateLeadRequest;
use App\Http\Resources\LeadResource;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class LeadController extends Controller
{
    public function index(ListLeadsRequest $request): AnonymousResourceCollection
    {
        $v = $request->validated();
        $q = trim($v['q'] ?? '');

        $leads = Lead::query()
            ->with('owner:id,name')
            ->when($q !== '', function ($query) use ($q) {
                // Escape LIKE wildcards; Postgres uses backslash as the default escape.
                $like = '%'.addcslashes($q, '\\%_').'%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('first_name', 'ilike', $like)
                        ->orWhere('last_name', 'ilike', $like)
                        ->orWhere('company', 'ilike', $like)
                        ->orWhere('email', 'ilike', $like);
                });
            })
            ->when($v['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy($v['sort'] ?? 'created_at', $v['direction'] ?? 'desc')
            ->orderBy('id') // stable tiebreaker so pages never overlap
            ->paginate($v['per_page'] ?? 25)
            ->withQueryString();

        return LeadResource::collection($leads);
    }

    public function show(Lead $lead): LeadResource
    {
        Gate::authorize('leads.view');

        return $this->present($lead);
    }

    public function store(StoreLeadRequest $request, CreateLeadAction $action): LeadResource
    {
        return $this->present($action->execute($request->user(), $request->validated()));
    }

    public function update(UpdateLeadRequest $request, Lead $lead, UpdateLeadAction $action): LeadResource
    {
        return $this->present($action->execute($request->user(), $lead, $request->validated()));
    }

    public function destroy(Request $request, Lead $lead, DeleteLeadAction $action): Response
    {
        $action->execute($request->user(), $lead);

        return response()->noContent();
    }

    /** Same response shape from every endpoint (owner always present). */
    private function present(Lead $lead): LeadResource
    {
        return new LeadResource($lead->load('owner:id,name'));
    }
}
'@

Patch-Once 'database\seeders\DevTenantSeeder.php' @'
        'accounts.view',
        'accounts.create',
        'accounts.update',
        'accounts.delete',
    ];
'@ @'
        'accounts.view',
        'accounts.create',
        'accounts.update',
        'accounts.delete',
        'leads.view',
        'leads.create',
        'leads.update',
        'leads.delete',
    ];
'@ 'leads.view'

Write-Host ""
Write-Host "Done. Run: php artisan tinker to re-run DevTenantSeeder, or:"
Write-Host "  php artisan db:seed --class=Database\Seeders\DevTenantSeeder"

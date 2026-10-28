$ErrorActionPreference = 'Stop'
$repo = 'C:\wamp64\www\crm-erp'
if (-not (Test-Path (Join-Path $repo 'artisan'))) { throw "artisan not found in $repo" }
Set-Location $repo

foreach ($p in @(
    'routes\api.php',
    'app\Domain\Inventory\Actions\SaveInventoryItemAction.php',
    'app\Domain\Inventory\Actions\RecordStockMovementAction.php',
    'app\Domain\Inventory\StockQuantity.php',
    'app\Models\InventoryItem.php',
    'app\Models\InventoryMovement.php'
)) { if (-not (Test-Path (Join-Path $repo $p))) { throw "Missing prerequisite: $p" } }

$utf8 = New-Object System.Text.UTF8Encoding($false)

function Write-NoBom([string]$Path, [string]$Content) {
    if (-not [System.IO.Path]::IsPathRooted($Path)) { $Path = Join-Path $repo $Path }
    $dir = [System.IO.Path]::GetDirectoryName($Path)
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $c = $Content.Replace("`r`n", "`n").TrimEnd("`n") + "`n"
    [System.IO.File]::WriteAllText($Path, $c, $utf8)
}

function Count-Sub([string]$Text, [string]$Sub) {
    $n = 0; $i = 0
    while (($i = $Text.IndexOf($Sub, $i, [System.StringComparison]::Ordinal)) -ge 0) { $n++; $i += $Sub.Length }
    return $n
}

function Patch-Once([string]$Path, [string]$Anchor, [string]$Replacement, [string]$Skip) {
    $p = Join-Path $repo $Path
    $raw = [System.IO.File]::ReadAllText($p, [System.Text.Encoding]::UTF8)
    $crlf = $raw.Contains("`r`n")
    $t = $raw.Replace("`r`n", "`n")
    if ($Skip -and $t.Contains($Skip)) { return 'skipped' }
    if ((Count-Sub $t $Anchor) -ne 1) { throw "Anchor must occur exactly once in $Path : $Anchor" }
    $t = $t.Replace($Anchor, $Replacement)
    if ($crlf) { $t = $t.Replace("`n", "`r`n") }
    [System.IO.File]::WriteAllText($p, $t, $utf8)
    return 'patched'
}

$report = New-Object System.Collections.Generic.List[string]
function Say([string]$m) { Write-Host $m; $script:report.Add($m) }

# ---------------------------------------------------------------- files

$routesPhp = @'
<?php

use App\Http\Controllers\Api\Inventory\InventoryItemController;
use App\Http\Controllers\Api\Inventory\InventoryMovementController;
use Illuminate\Support\Facades\Route;

// 11.0b: inventory. Required from routes/api.php INSIDE the authenticated group (auth:sanctum, resolve.tenant).
// {item} is a plain id (no route-model binding): every lookup carries an explicit tenant predicate.
Route::get('inventory/items', [InventoryItemController::class, 'index']);

Route::post('inventory/items', [InventoryItemController::class, 'store'])->middleware('throttle:30,1');

Route::get('inventory/items/{item}', [InventoryItemController::class, 'show'])->whereNumber('item');

Route::put('inventory/items/{item}', [InventoryItemController::class, 'update'])->whereNumber('item')->middleware('throttle:30,1');

// The ledger: read the append-only history, or append one movement (receipt | issue | adjustment).
Route::get('inventory/items/{item}/movements', [InventoryMovementController::class, 'index'])->whereNumber('item');

Route::post('inventory/items/{item}/movements', [InventoryMovementController::class, 'store'])->whereNumber('item')->middleware('throttle:60,1');
'@

$itemResourcePhp = @'
<?php

namespace App\Http\Resources;

use App\Domain\Inventory\StockQuantity;
use App\Models\InventoryItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An inventory item as the SPA sees it (11.0b). Explicit whitelist: no tenant id. Quantities are decimal strings.
 * low_stock: an active item with a reorder level whose on-hand has fallen to or below it.
 *
 * @mixin InventoryItem
 */
class InventoryItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $onHand = StockQuantity::fromDecimal($this->on_hand);
        $reorder = StockQuantity::fromDecimal($this->reorder_level);

        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'unit' => $this->unit,
            'on_hand' => $this->on_hand,
            'reorder_level' => $this->reorder_level,
            'is_active' => (bool) $this->is_active,
            'low_stock' => (bool) $this->is_active && $reorder > 0 && $onHand <= $reorder,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
'@

$movementResourcePhp = @'
<?php

namespace App\Http\Resources;

use App\Models\InventoryMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One ledger row (11.0b). Explicit whitelist: no tenant id. quantity_delta is signed, balance_after is the item's
 * on-hand right after this movement.
 *
 * @mixin InventoryMovement
 */
class InventoryMovementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'inventory_item_id' => $this->inventory_item_id,
            'type' => $this->type,
            'quantity_delta' => $this->quantity_delta,
            'balance_after' => $this->balance_after,
            'reference' => $this->reference,
            'note' => $this->note,
            'recorded_by' => $this->recorded_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
'@

$listRequestPhp = @'
<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** 11.0b: query-string shape for the item list. Sort is a whitelist: the column name never comes from the client. */
class ListInventoryItemsRequest extends FormRequest
{
    public const SORT_COLUMNS = [
        'sku' => 'sku',
        'name' => 'name',
        'on_hand' => 'on_hand',
        'reorder_level' => 'reorder_level',
        'created_at' => 'created_at',
    ];

    public function authorize(): bool
    {
        return $this->user()?->can('inventory.view') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'active' => ['nullable', 'boolean'],
            'low_stock' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'string', Rule::in(array_keys(self::SORT_COLUMNS))],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
'@

$saveRequestPhp = @'
<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/** Shape check only. SaveInventoryItemAction re-checks permission, formats, uniqueness and SKU immutability (2.4). */
class SaveInventoryItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $v = $this->input('reorder_level');
        if (is_int($v) || is_float($v)) {
            $this->merge(['reorder_level' => (string) $v]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:160'],
            'unit' => ['nullable', 'string', 'max:24'],
            'reorder_level' => ['nullable', 'string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
'@

$movementRequestPhp = @'
<?php

namespace App\Http\Requests\Inventory;

use App\Domain\Inventory\Actions\RecordStockMovementAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape check only. RecordStockMovementAction re-checks everything under the item row lock (2.4): quantity format and
 * sign, insufficient stock, adjustment reason, inactive items. The client never sends a balance.
 */
class RecordStockMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $v = $this->input('quantity');
        if (is_int($v) || is_float($v)) {
            $this->merge(['quantity' => (string) $v]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(RecordStockMovementAction::TYPES)],
            'quantity' => ['required', 'string', 'max:20'],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
'@

$itemControllerPhp = @'
<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Domain\Inventory\Actions\SaveInventoryItemAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\ListInventoryItemsRequest;
use App\Http\Requests\Inventory\SaveInventoryItemRequest;
use App\Http\Resources\InventoryItemResource;
use App\Models\InventoryItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

// {item} is a plain id on purpose (no route-model binding): every lookup carries an explicit tenant predicate.
final class InventoryItemController extends Controller
{
    public function index(ListInventoryItemsRequest $request): AnonymousResourceCollection
    {
        $v = $request->validated();
        $tenantId = (int) $request->user()->tenant_id;
        $q = trim((string) ($v['q'] ?? ''));
        $direction = $v['direction'] ?? 'asc';
        $column = ListInventoryItemsRequest::SORT_COLUMNS[$v['sort'] ?? 'sku'];

        $items = InventoryItem::query()
            ->where('tenant_id', $tenantId)
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.addcslashes($q, '\\%_').'%';
                $query->where(function ($w) use ($like) {
                    $w->where('sku', 'ilike', $like)->orWhere('name', 'ilike', $like);
                });
            })
            ->when(isset($v['active']), fn ($query) => $query->where('is_active', filter_var($v['active'], FILTER_VALIDATE_BOOLEAN)))
            ->when(isset($v['low_stock']) && filter_var($v['low_stock'], FILTER_VALIDATE_BOOLEAN), function ($query) {
                $query->where('is_active', true)->where('reorder_level', '>', 0)->whereColumn('on_hand', '<=', 'reorder_level');
            })
            ->orderBy($column, $direction)
            ->orderBy('id', $direction) // stable tiebreaker so pages never overlap
            ->paginate($v['per_page'] ?? 25)
            ->withQueryString();

        return InventoryItemResource::collection($items);
    }

    public function show(Request $request, int $item): InventoryItemResource
    {
        Gate::authorize('inventory.view');

        return new InventoryItemResource(
            InventoryItem::query()->where('tenant_id', (int) $request->user()->tenant_id)->findOrFail($item),
        );
    }

    public function store(SaveInventoryItemRequest $request, SaveInventoryItemAction $action): JsonResponse
    {
        $item = $action->execute($request->user(), null, $request->validated());

        return (new InventoryItemResource($item))->response()->setStatusCode(201);
    }

    public function update(SaveInventoryItemRequest $request, int $item, SaveInventoryItemAction $action): InventoryItemResource
    {
        return new InventoryItemResource($action->execute($request->user(), $item, $request->validated()));
    }
}
'@

$movementControllerPhp = @'
<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Domain\Inventory\Actions\RecordStockMovementAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\RecordStockMovementRequest;
use App\Http\Resources\InventoryMovementResource;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

// {item} is a plain id on purpose (no route-model binding): every lookup carries an explicit tenant predicate.
final class InventoryMovementController extends Controller
{
    /** The ledger of one item, newest first. */
    public function index(Request $request, int $item): AnonymousResourceCollection
    {
        Gate::authorize('inventory.view');

        $v = $request->validate([
            'type' => ['nullable', 'string', Rule::in(RecordStockMovementAction::TYPES)],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $tenantId = (int) $request->user()->tenant_id;
        $itemId = InventoryItem::query()->where('tenant_id', $tenantId)->findOrFail($item)->id;

        $rows = InventoryMovement::query()
            ->where('tenant_id', $tenantId)
            ->where('inventory_item_id', $itemId)
            ->when($v['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->orderByDesc('id')
            ->paginate($v['per_page'] ?? 25)
            ->withQueryString();

        return InventoryMovementResource::collection($rows);
    }

    public function store(RecordStockMovementRequest $request, int $item, RecordStockMovementAction $action): JsonResponse
    {
        $v = $request->validated();

        $movement = $action->execute(
            $request->user(),
            $item,
            (string) $v['type'],
            (string) $v['quantity'],
            $v['reference'] ?? null,
            $v['note'] ?? null,
        );

        return (new InventoryMovementResource($movement))->response()->setStatusCode(201);
    }
}
'@

$apiTestPhp = @'
<?php

use App\Domain\Inventory\Actions\RecordStockMovementAction;
use App\Domain\Inventory\Actions\SaveInventoryItemAction;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

function stkapiSwitch(Tenant $tenant): void
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
}

function stkapiUser(Tenant $tenant, ?string $role = 'manager'): User
{
    stkapiSwitch($tenant);
    RoleCatalog::provision($tenant);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    if ($role !== null) {
        $user->assignRole($role);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

function stkapiItem(User $actor, string $sku, string $name = 'Item', string $reorder = '0', ?string $stock = null): int
{
    stkapiSwitch(Tenant::query()->findOrFail($actor->tenant_id));

    $id = app(SaveInventoryItemAction::class)->execute($actor, null, ['sku' => $sku, 'name' => $name, 'unit' => 'pcs', 'reorder_level' => $reorder])->id;

    if ($stock !== null) {
        app(RecordStockMovementAction::class)->execute($actor, $id, 'receipt', $stock);
    }

    return $id;
}

it('stkapi lists only the tenants items with filters, search and sorting', function () {
    $b = Tenant::factory()->create();
    stkapiItem(stkapiUser($b), 'THEIRS', 'Other tenant');

    $a = Tenant::factory()->create();
    $manager = stkapiUser($a);
    $viewer = stkapiUser($a, 'viewer');
    $noRole = stkapiUser($a, null);
    $bolt = stkapiItem($manager, 'BOLT', 'Bolt', '10', '4');   // low stock
    $nut = stkapiItem($manager, 'NUT', 'Nut', '10', '50');     // fine
    $washer = stkapiItem($manager, 'WASHER', 'Washer');        // no reorder level
    app(SaveInventoryItemAction::class)->execute($manager, $washer, ['sku' => 'WASHER', 'name' => 'Washer', 'is_active' => false]);

    Sanctum::actingAs($viewer); // read-only is enough to list
    $this->getJson('/api/v1/inventory/items')
        ->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('data.0.sku', 'BOLT') // sku ascending by default
        ->assertJsonPath('data.0.on_hand', '4.000')
        ->assertJsonPath('data.0.low_stock', true)
        ->assertJsonPath('data.1.low_stock', false)
        ->assertJsonMissingPath('data.0.tenant_id');

    $this->getJson('/api/v1/inventory/items?low_stock=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $bolt);
    $this->getJson('/api/v1/inventory/items?active=0')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $washer);
    $this->getJson('/api/v1/inventory/items?active=1')->assertOk()->assertJsonPath('meta.total', 2);
    $this->getJson('/api/v1/inventory/items?q=nu')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $nut);
    $this->getJson('/api/v1/inventory/items?sort=on_hand&direction=desc')->assertOk()->assertJsonPath('data.0.id', $nut);
    $this->getJson('/api/v1/inventory/items?sort=bogus')->assertStatus(422)->assertJsonValidationErrors('sort');
    $this->getJson('/api/v1/inventory/items?per_page=500')->assertStatus(422)->assertJsonValidationErrors('per_page');

    Sanctum::actingAs($noRole);
    $this->getJson('/api/v1/inventory/items')->assertForbidden();
});

it('stkapi creates, shows and edits items with the right permissions', function () {
    $b = Tenant::factory()->create();
    $theirs = stkapiItem(stkapiUser($b), 'THEIRS');

    $a = Tenant::factory()->create();
    $manager = stkapiUser($a);
    $sales = stkapiUser($a, 'sales');
    $viewer = stkapiUser($a, 'viewer');

    Sanctum::actingAs($sales);
    $this->postJson('/api/v1/inventory/items', ['sku' => 'x1', 'name' => 'X'])->assertForbidden();
    Sanctum::actingAs($viewer);
    $this->postJson('/api/v1/inventory/items', ['sku' => 'x1', 'name' => 'X'])->assertForbidden();

    Sanctum::actingAs($manager);
    $this->postJson('/api/v1/inventory/items', [])->assertStatus(422)->assertJsonValidationErrors(['sku', 'name']);
    $this->postJson('/api/v1/inventory/items', ['sku' => '!!', 'name' => 'X'])->assertStatus(422)->assertJsonValidationErrors('sku');
    $this->postJson('/api/v1/inventory/items', ['sku' => 'x1', 'name' => 'X', 'reorder_level' => '-2'])->assertStatus(422)->assertJsonValidationErrors('reorder_level');

    $id = (int) $this->postJson('/api/v1/inventory/items', ['sku' => 'bolt-1', 'name' => 'Bolt', 'unit' => 'pcs', 'reorder_level' => 12.5])
        ->assertCreated()
        ->assertJsonPath('sku', 'BOLT-1')
        ->assertJsonPath('on_hand', '0.000')
        ->assertJsonPath('reorder_level', '12.500')
        ->assertJsonPath('is_active', true)
        ->assertJsonMissingPath('tenant_id')
        ->json('id');

    $this->postJson('/api/v1/inventory/items', ['sku' => 'BOLT-1', 'name' => 'Dup'])->assertStatus(422)->assertJsonValidationErrors('sku');

    $this->putJson("/api/v1/inventory/items/{$id}", ['sku' => 'BOLT-1', 'name' => 'Bolt zinc', 'unit' => 'box', 'reorder_level' => '3', 'is_active' => false])
        ->assertOk()
        ->assertJsonPath('name', 'Bolt zinc')
        ->assertJsonPath('unit', 'box')
        ->assertJsonPath('reorder_level', '3.000')
        ->assertJsonPath('is_active', false);
    $this->putJson("/api/v1/inventory/items/{$id}", ['sku' => 'CHANGED', 'name' => 'x'])->assertStatus(422)->assertJsonValidationErrors('sku');
    $this->putJson("/api/v1/inventory/items/{$theirs}", ['sku' => 'THEIRS', 'name' => 'Hijack'])->assertNotFound();

    Sanctum::actingAs($viewer);
    $this->getJson("/api/v1/inventory/items/{$id}")->assertOk()->assertJsonPath('sku', 'BOLT-1')->assertJsonMissingPath('tenant_id');
    $this->getJson("/api/v1/inventory/items/{$theirs}")->assertNotFound();
    $this->getJson('/api/v1/inventory/items/abc')->assertNotFound();
    $this->putJson("/api/v1/inventory/items/{$id}", ['sku' => 'BOLT-1', 'name' => 'x'])->assertForbidden();
});

it('stkapi appends movements and serves the ledger newest first', function () {
    $b = Tenant::factory()->create();
    $theirs = stkapiItem(stkapiUser($b), 'THEIRS');

    $a = Tenant::factory()->create();
    $manager = stkapiUser($a);
    $sales = stkapiUser($a, 'sales');
    $viewer = stkapiUser($a, 'viewer');
    $id = stkapiItem($manager, 'BOLT', 'Bolt', '5');
    $post = fn (array $body) => $this->postJson("/api/v1/inventory/items/{$id}/movements", $body);

    Sanctum::actingAs($sales);
    $post(['type' => 'receipt', 'quantity' => '1'])->assertForbidden();

    Sanctum::actingAs($manager);
    $post([])->assertStatus(422)->assertJsonValidationErrors(['type', 'quantity']);
    $post(['type' => 'bogus', 'quantity' => '1'])->assertStatus(422)->assertJsonValidationErrors('type');
    $post(['type' => 'receipt', 'quantity' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('quantity');
    $post(['type' => 'receipt', 'quantity' => '0'])->assertStatus(422)->assertJsonValidationErrors('quantity');

    $post(['type' => 'receipt', 'quantity' => 10, 'reference' => 'PO-1'])
        ->assertCreated()
        ->assertJsonPath('type', 'receipt')
        ->assertJsonPath('quantity_delta', '10.000')
        ->assertJsonPath('balance_after', '10.000')
        ->assertJsonPath('reference', 'PO-1')
        ->assertJsonMissingPath('tenant_id');

    $post(['type' => 'issue', 'quantity' => '4.5'])->assertCreated()->assertJsonPath('quantity_delta', '-4.500')->assertJsonPath('balance_after', '5.500');
    $post(['type' => 'issue', 'quantity' => '6'])->assertStatus(422)->assertJsonValidationErrors('quantity'); // only 5.500 on hand
    $post(['type' => 'adjustment', 'quantity' => '-1'])->assertStatus(422)->assertJsonValidationErrors('note'); // reason required
    $post(['type' => 'adjustment', 'quantity' => '-1', 'note' => 'Damaged'])->assertCreated()->assertJsonPath('balance_after', '4.500');

    $this->getJson("/api/v1/inventory/items/{$id}")->assertOk()->assertJsonPath('on_hand', '4.500')->assertJsonPath('low_stock', true);

    Sanctum::actingAs($viewer);
    $this->getJson("/api/v1/inventory/items/{$id}/movements")
        ->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('data.0.type', 'adjustment') // newest first
        ->assertJsonPath('data.0.balance_after', '4.500')
        ->assertJsonPath('data.2.type', 'receipt')
        ->assertJsonMissingPath('data.0.tenant_id');
    $this->getJson("/api/v1/inventory/items/{$id}/movements?type=issue")->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson("/api/v1/inventory/items/{$id}/movements?type=bogus")->assertStatus(422)->assertJsonValidationErrors('type');

    // Another tenant's item is invisible and untouchable.
    $this->getJson("/api/v1/inventory/items/{$theirs}/movements")->assertNotFound();
    Sanctum::actingAs($manager);
    $this->postJson("/api/v1/inventory/items/{$theirs}/movements", ['type' => 'receipt', 'quantity' => '1'])->assertNotFound();

    stkapiSwitch($b);
    expect(DB::table('inventory_movements')->where('inventory_item_id', $theirs)->count())->toBe(0);
});
'@

# ---------------------------------------------------------------- route patch

$patches = New-Object System.Collections.ArrayList
[void]$patches.Add(@{
    Path = 'routes\api.php'
    Anchor = "require __DIR__ . '/invoices.php';"
    Replacement = "require __DIR__ . '/invoices.php';`n        // 11.0b: inventory (routes/inventory.php)`n        require __DIR__ . '/inventory.php';"
    Skip = "'/inventory.php'"
})

foreach ($pt in $patches) {
    $p = Join-Path $repo $pt.Path
    $t = [System.IO.File]::ReadAllText($p, [System.Text.Encoding]::UTF8).Replace("`r`n", "`n")
    if ($pt.Skip -and $t.Contains($pt.Skip)) { continue }
    $n = Count-Sub $t $pt.Anchor
    if ($n -ne 1) { throw "PREFLIGHT: anchor occurs $n times in $($pt.Path): $($pt.Anchor)" }
}
Say 'Preflight OK.'

# ---------------------------------------------------------------- write + patch

Write-NoBom 'routes\inventory.php' $routesPhp
Write-NoBom 'app\Http\Resources\InventoryItemResource.php' $itemResourcePhp
Write-NoBom 'app\Http\Resources\InventoryMovementResource.php' $movementResourcePhp
Write-NoBom 'app\Http\Requests\Inventory\ListInventoryItemsRequest.php' $listRequestPhp
Write-NoBom 'app\Http\Requests\Inventory\SaveInventoryItemRequest.php' $saveRequestPhp
Write-NoBom 'app\Http\Requests\Inventory\RecordStockMovementRequest.php' $movementRequestPhp
Write-NoBom 'app\Http\Controllers\Api\Inventory\InventoryItemController.php' $itemControllerPhp
Write-NoBom 'app\Http\Controllers\Api\Inventory\InventoryMovementController.php' $movementControllerPhp
Write-NoBom 'tests\Feature\Inventory\StockApiTest.php' $apiTestPhp
Say 'Wrote 9 files.'

foreach ($pt in $patches) {
    $res = Patch-Once $pt.Path $pt.Anchor $pt.Replacement $pt.Skip
    Say ("{0}: {1}" -f $pt.Path, $res)
}

# ---------------------------------------------------------------- tests

$ErrorActionPreference = 'Continue'
& php artisan route:clear 2>&1 | Out-Null

$out1 = (& php artisan test tests\Feature\Inventory 2>&1 | Out-String)
$ok1 = ($LASTEXITCODE -eq 0)
Say '--- tests\Feature\Inventory ---'
Say (($out1 -split "`r?`n" | Select-Object -Last 30) -join "`n")

if ($ok1) {
    Write-Host 'Running the full suite (about 5-9 minutes, output appears at the end)...'
    $out2 = (& php artisan test 2>&1 | Out-String)
    Say '--- full suite ---'
    Say (($out2 -split "`r?`n" | Select-Object -Last 15) -join "`n")
} else {
    Say 'Skipped the full suite because the inventory tests did not pass.'
}
$ErrorActionPreference = 'Stop'

Set-Clipboard -Value ($report -join "`r`n")
Say 'Report copied'
Read-Host 'Press Enter to close'
Write-Host 'NOT TOUCHED (referenced, not shown): the frontend (11.0c builds the Inventory pages and the /inventory route), the Action files (unchanged), the other route files.'

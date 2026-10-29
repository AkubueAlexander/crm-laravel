<?php

use App\Domain\Inventory\Actions\RecordStockMovementAction;
use App\Domain\Inventory\Actions\SaveInventoryItemAction;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\LowStockNotification;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    config(['broadcasting.default' => 'null']);
});

function lowstkUser(Tenant $tenant, ?string $role): User
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
    RoleCatalog::provision($tenant);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    if ($role !== null) {
        $user->assignRole($role);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

function lowstkItem(User $actor, string $sku, string $reorder, string $stock): int
{
    $id = app(SaveInventoryItemAction::class)->execute($actor, null, ['sku' => $sku, 'name' => $sku.' item', 'unit' => 'pcs', 'reorder_level' => $reorder])->id;
    app(RecordStockMovementAction::class)->execute($actor, $id, 'receipt', $stock);

    return $id;
}

function lowstkMove(User $actor, int $itemId, string $type, string $qty, ?string $note = null): void
{
    app(RecordStockMovementAction::class)->execute($actor, $itemId, $type, $qty, null, $note);
}

it('lowstk alerts inventory managers of the tenant when stock crosses the reorder level', function () {
    $b = Tenant::factory()->create();
    $bAdmin = lowstkUser($b, 'admin');

    $a = Tenant::factory()->create();
    $admin = lowstkUser($a, 'admin');
    $mgr = lowstkUser($a, 'manager');
    $sales = lowstkUser($a, 'sales');
    $viewer = lowstkUser($a, 'viewer');
    $gone = lowstkUser($a, 'manager');
    DB::table('users')->where('id', $gone->id)->update(['deactivated_at' => now()]);

    $id = lowstkItem($mgr, 'BOLT', '10', '12'); // receipt raises stock: no alert
    expect(DB::table('notifications')->count())->toBe(0);

    lowstkMove($mgr, $id, 'issue', '3'); // 12 -> 9, crosses 10

    expect($admin->notifications()->count())->toBe(1)
        ->and($mgr->notifications()->count())->toBe(1)
        ->and($sales->notifications()->count())->toBe(0)
        ->and($viewer->notifications()->count())->toBe(0)
        ->and($gone->notifications()->count())->toBe(0)
        ->and($bAdmin->notifications()->count())->toBe(0);

    $n = $admin->notifications()->first();
    expect($n->type)->toBe(LowStockNotification::class)
        ->and($n->data['kind'])->toBe('low_stock')
        ->and($n->data['item_id'])->toBe($id)
        ->and($n->data['sku'])->toBe('BOLT')
        ->and($n->data['on_hand'])->toBe('9.000')
        ->and($n->data['reorder_level'])->toBe('10.000')
        ->and((int) $n->data['tenant_id'])->toBe((int) $a->id);

    // The inbox API serves it without the tenant id.
    Sanctum::actingAs($admin);
    $this->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('meta.unread_count', 1)
        ->assertJsonPath('data.0.kind', 'low_stock')
        ->assertJsonPath('data.0.data.sku', 'BOLT')
        ->assertJsonPath('data.0.data.item_id', $id)
        ->assertJsonMissingPath('data.0.data.tenant_id');
});

it('lowstk alerts once per crossing and re-arms after stock recovers', function () {
    $a = Tenant::factory()->create();
    $mgr = lowstkUser($a, 'manager');

    $id = lowstkItem($mgr, 'NUT', '10', '20');
    $count = fn () => $mgr->notifications()->count();

    lowstkMove($mgr, $id, 'issue', '5');  // 15
    lowstkMove($mgr, $id, 'issue', '4');  // 11
    expect($count())->toBe(0);

    lowstkMove($mgr, $id, 'issue', '1');  // 10: at the level counts as low
    expect($count())->toBe(1);

    lowstkMove($mgr, $id, 'issue', '2');  // 8: already low, no new alert
    lowstkMove($mgr, $id, 'receipt', '5'); // 13: recovered
    expect($count())->toBe(1);

    lowstkMove($mgr, $id, 'adjustment', '-4', 'Stock take'); // 9: crosses again
    expect($count())->toBe(2);

    // No reorder level, no alerts.
    $free = lowstkItem($mgr, 'WASHER', '0', '5');
    lowstkMove($mgr, $free, 'issue', '5');
    expect($count())->toBe(2);
});

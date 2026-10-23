$ErrorActionPreference = 'Stop'
Write-Host 'crm-erp 8b.2b notifications inbox API v1'

$Utf8NoBom = New-Object System.Text.UTF8Encoding($false)

function Write-NoBom {
    param([string]$Path, [string]$Content)
    $full = Join-Path (Get-Location).Path $Path
    $dir = Split-Path $full -Parent
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $useCrlf = $false
    if (Test-Path $full) {
        $existing = [System.IO.File]::ReadAllText($full)
        if ($existing.Contains("`r`n")) { $useCrlf = $true }
    }
    $text = $Content -replace "`r`n", "`n"
    $text = $text.TrimEnd("`n") + "`n"
    if ($useCrlf) { $text = $text -replace "`n", "`r`n" }
    [System.IO.File]::WriteAllText($full, $text, $Utf8NoBom)
    Write-Host "  wrote $Path"
}

function Patch-Once {
    param([string]$Path, [string]$Old, [string]$New, [string]$Marker)
    $full = Join-Path (Get-Location).Path $Path
    if (-not (Test-Path $full)) { throw "Patch target not found: $Path" }
    $raw = [System.IO.File]::ReadAllText($full)
    $crlf = $raw.Contains("`r`n")
    $norm = $raw -replace "`r`n", "`n"
    if ($norm.Contains($Marker)) { Write-Host "  skip $Path (already patched)"; return }
    $oldN = $Old -replace "`r`n", "`n"
    $newN = $New -replace "`r`n", "`n"
    $count = ([regex]::Matches($norm, [regex]::Escape($oldN))).Count
    if ($count -ne 1) { throw "Anchor in $Path matched $count times (expected 1): $oldN" }
    $norm = $norm.Replace($oldN, $newN)
    if ($crlf) { $norm = $norm -replace "`n", "`r`n" }
    [System.IO.File]::WriteAllText($full, $norm, $Utf8NoBom)
    Write-Host "  patched $Path"
}

# Guard: this chunk builds on the production side (table + notification + channel) that already exists.
foreach ($req in @('app\Notifications\ActivityOverdueNotification.php', 'app\Broadcasting\UserChannel.php')) {
    if (-not (Test-Path $req)) { throw "Missing prerequisite: $req" }
}
if (-not (Get-ChildItem 'database\migrations' -File | Where-Object { $_.Name -match 'create_notifications_table' })) {
    throw 'Missing prerequisite: the create_notifications_table migration.'
}

# ---------------------------------------------------------------- 1. Inbox
Write-NoBom 'app\Domain\Notifications\Inbox.php' @'
<?php

namespace App\Domain\Notifications;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The single place that defines "which notifications may this user see".
 * Scoped to the user AND to the tenant stored in the notification data, so a user
 * who later belongs to several tenants never sees one tenant's items under another.
 * The data->>'tenant_id' predicate matches the expression indexes on notifications.
 */
class Inbox
{
    public static function for(User $user): Builder
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->where('data->tenant_id', (string) $user->tenant_id);
    }

    public static function unread(User $user): Builder
    {
        return self::for($user)->whereNull('read_at');
    }
}
'@

# ---------------------------------------------------------------- 2. Mark one read
Write-NoBom 'app\Domain\Notifications\MarkNotificationReadAction.php' @'
<?php

namespace App\Domain\Notifications;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

class MarkNotificationReadAction
{
    public function execute(User $user, string $id): DatabaseNotification
    {
        // A non-uuid would raise a Postgres type error (500) instead of a clean 404.
        abort_unless(Str::isUuid($id), 404, 'Notification not found.');

        // Resolved through the inbox scope: another user's or another tenant's row is a 404.
        $notification = Inbox::for($user)->whereKey($id)->firstOrFail();

        $notification->markAsRead();

        return $notification;
    }
}
'@

# ---------------------------------------------------------------- 3. Mark all read
Write-NoBom 'app\Domain\Notifications\MarkAllNotificationsReadAction.php' @'
<?php

namespace App\Domain\Notifications;

use App\Models\User;

class MarkAllNotificationsReadAction
{
    /** @return int number of notifications marked read */
    public function execute(User $user): int
    {
        return Inbox::unread($user)->update(['read_at' => now()]);
    }
}
'@

# ---------------------------------------------------------------- 4. Resource
Write-NoBom 'app\Http\Resources\NotificationResource.php' @'
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/** @mixin \Illuminate\Notifications\DatabaseNotification */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = is_array($this->data) ? $this->data : [];

        return [
            'id' => $this->id,
            'kind' => $data['kind'] ?? 'unknown',
            // tenant_id is server bookkeeping; never sent to the client (7.0).
            'data' => Arr::except($data, ['tenant_id', 'kind']),
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
'@

# ---------------------------------------------------------------- 5. Controller
Write-NoBom 'app\Http\Controllers\Api\Notifications\NotificationsController.php' @'
<?php

namespace App\Http\Controllers\Api\Notifications;

use App\Domain\Notifications\Inbox;
use App\Domain\Notifications\MarkAllNotificationsReadAction;
use App\Domain\Notifications\MarkNotificationReadAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = min(max($request->integer('per_page', 15), 1), 50);

        $page = Inbox::for($user)->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'data' => NotificationResource::collection($page->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'unread_count' => Inbox::unread($user)->count(),
            ],
        ]);
    }

    public function read(Request $request, string $id, MarkNotificationReadAction $action): NotificationResource
    {
        return new NotificationResource($action->execute($request->user(), $id));
    }

    public function readAll(Request $request, MarkAllNotificationsReadAction $action): JsonResponse
    {
        $marked = $action->execute($request->user());

        return response()->json(['marked' => $marked, 'unread_count' => 0]);
    }
}
'@

# ---------------------------------------------------------------- 6. Routes file
Write-NoBom 'routes\notifications.php' @'
<?php

use App\Http\Controllers\Api\Notifications\NotificationsController;
use Illuminate\Support\Facades\Route;

// Loaded from bootstrap/app.php (withRouting then:) inside api + auth:sanctum + resolve.tenant, prefix /api/v1.
Route::middleware('throttle:120,1')->group(function () {
    Route::get('notifications', [NotificationsController::class, 'index']);
    Route::post('notifications/read-all', [NotificationsController::class, 'readAll']);
    Route::post('notifications/{id}/read', [NotificationsController::class, 'read']);
});
'@

# ---------------------------------------------------------------- 7. Tests (prefix inbx: ntf* is taken)
Write-NoBom 'tests\Feature\Notifications\NotificationInboxApiTest.php' @'
<?php

use App\Domain\Tenant\TenantContext;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

function inbxTenant(string $slug)
{
    $class = class_exists(\App\Models\Tenant::class)
        ? \App\Models\Tenant::class
        : \App\Domain\Tenant\Tenant::class;

    return $class::create(['name' => $slug, 'slug' => $slug]);
}

function inbxSwitch($tenant): void
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
}

function inbxUser($tenant): User
{
    inbxSwitch($tenant);

    return User::factory()->create(['tenant_id' => $tenant->id]);
}

function inbxMake(User $user, int|string $tenantId, bool $read = false, string $subject = 'Call'): DatabaseNotification
{
    return DatabaseNotification::forceCreate([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\TestNotification',
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->id,
        'data' => ['tenant_id' => (int) $tenantId, 'kind' => 'activity_overdue', 'subject' => $subject],
        'read_at' => $read ? now() : null,
    ]);
}

function inbxGet(User $user, string $uri = '/api/v1/notifications')
{
    Sanctum::actingAs($user);

    return test()->getJson($uri);
}

it('lists own notifications for the current tenant with an unread count', function () {
    $a = inbxTenant('inbx-a');
    $user = inbxUser($a);
    inbxMake($user, $a->id, false, 'One');
    inbxMake($user, $a->id, true, 'Two');

    inbxGet($user)
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.unread_count', 1)
        ->assertJsonPath('data.0.kind', 'activity_overdue')
        ->assertJsonMissingPath('data.0.data.tenant_id');
});

it('never lists another users notifications in the same tenant', function () {
    $a = inbxTenant('inbx-a');
    $me = inbxUser($a);
    $other = inbxUser($a);
    inbxMake($other, $a->id);

    inbxGet($me)->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.unread_count', 0);
});

it('hides a notification stamped with a different tenant even for the same user', function () {
    $a = inbxTenant('inbx-a');
    $b = inbxTenant('inbx-b');
    $user = inbxUser($a);
    inbxMake($user, $b->id, false, 'Foreign');
    inbxMake($user, $a->id, false, 'Mine');

    inbxGet($user)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.data.subject', 'Mine');
});

it('marks one notification read', function () {
    $a = inbxTenant('inbx-a');
    $user = inbxUser($a);
    $n = inbxMake($user, $a->id);

    Sanctum::actingAs($user);
    $this->postJson("/api/v1/notifications/{$n->id}/read")
        ->assertOk()
        ->assertJsonPath('id', $n->id);

    expect($n->fresh()->read_at)->not->toBeNull();
});

it('returns 404 when marking another users notification read', function () {
    $a = inbxTenant('inbx-a');
    $me = inbxUser($a);
    $other = inbxUser($a);
    $n = inbxMake($other, $a->id);

    Sanctum::actingAs($me);
    $this->postJson("/api/v1/notifications/{$n->id}/read")->assertNotFound();

    expect($n->fresh()->read_at)->toBeNull();
});

it('returns 404 for a malformed notification id', function () {
    $a = inbxTenant('inbx-a');
    $user = inbxUser($a);

    Sanctum::actingAs($user);
    $this->postJson('/api/v1/notifications/not-a-uuid/read')->assertNotFound();
});

it('marks all of the callers current-tenant notifications read and nothing else', function () {
    $a = inbxTenant('inbx-a');
    $b = inbxTenant('inbx-b');
    $me = inbxUser($a);
    $other = inbxUser($a);
    inbxMake($me, $a->id);
    inbxMake($me, $a->id);
    inbxMake($me, $b->id);
    inbxMake($other, $a->id);

    Sanctum::actingAs($me);
    $this->postJson('/api/v1/notifications/read-all')
        ->assertOk()
        ->assertJsonPath('marked', 2)
        ->assertJsonPath('unread_count', 0);

    // The foreign-tenant row and the other user's row are untouched.
    expect(DatabaseNotification::whereNull('read_at')->count())->toBe(2);
});

it('requires authentication', function () {
    $this->getJson('/api/v1/notifications')->assertUnauthorized();
});
'@

# ---------------------------------------------------------------- 8. Wire routes (bootstrap/app.php)
Patch-Once -Path 'bootstrap\app.php' `
    -Old 'use Illuminate\Validation\ValidationException;' `
    -New ("use Illuminate\Support\Facades\Route;`nuse Illuminate\Validation\ValidationException;") `
    -Marker 'use Illuminate\Support\Facades\Route;'

Patch-Once -Path 'bootstrap\app.php' `
    -Old "health: '/up'," `
    -New ("health: '/up',`n        then: function () {`n            Route::middleware(['api', 'auth:sanctum', 'resolve.tenant'])`n                ->prefix('api/v1')`n                ->group(base_path('routes/notifications.php'));`n        },") `
    -Marker 'routes/notifications.php'

Write-Host ''
Write-Host 'Done. Next: php artisan test --filter=NotificationInbox ; php artisan test'
Write-Host 'NOTE: not shown, so not touched: routes/api.php (notification routes load via bootstrap/app.php then:), the Tenant model namespace (test helper falls back between App\Models\Tenant and App\Domain\Tenant\Tenant), and how routes/channels.php is loaded.'

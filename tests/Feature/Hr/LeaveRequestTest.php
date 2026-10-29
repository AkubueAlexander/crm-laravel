<?php

use App\Domain\Hr\Actions\SaveLeaveRequestAction;
use App\Domain\Hr\Actions\TransitionLeaveRequestAction;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

function lvreqEmployee(int $tenantId, string $name, ?int $userId, ?int $createdBy): int
{
    $employee = new Employee();
    $employee->forceFill([
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'name' => $name,
        'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
        'is_active' => true,
        'created_by' => $createdBy,
    ])->save();

    return (int) $employee->id;
}

function lvreqUser(Tenant $tenant, string $role): User
{
    TenantContext::set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** @return array<string, mixed> */
function lvreqWorld(): array
{
    // The OTHER tenant first, so TenantContext ends on the tenant under test.
    $other = Tenant::factory()->create();
    TenantContext::set($other);
    app(PermissionRegistrar::class)->setPermissionsTeamId($other->id);
    RoleCatalog::provision($other);
    $otherAdmin = lvreqUser($other, 'admin');
    $otherEmp = lvreqEmployee($other->id, 'Olga Other', null, $otherAdmin->id);
    $otherLeave = DB::table('leave_requests')->insertGetId([
        'tenant_id' => $other->id,
        'employee_id' => $otherEmp,
        'type' => 'annual',
        'start_date' => '2031-03-03',
        'end_date' => '2031-03-07',
        'status' => 'approved',
        'lock_version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $tenant = Tenant::factory()->create();
    TenantContext::set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
    RoleCatalog::provision($tenant);

    $admin = lvreqUser($tenant, 'admin');
    $manager = lvreqUser($tenant, 'manager');
    $ann = lvreqUser($tenant, 'sales');
    $sam = lvreqUser($tenant, 'sales');
    $viewer = lvreqUser($tenant, 'viewer');

    return [
        'tenant' => $tenant,
        'admin' => $admin,
        'manager' => $manager,
        'ann' => $ann,
        'sam' => $sam,
        'viewer' => $viewer,
        'annE' => lvreqEmployee($tenant->id, 'Ann Rep', $ann->id, $admin->id),
        'samE' => lvreqEmployee($tenant->id, 'Sam Rep', $sam->id, $admin->id),
        'otherLeave' => $otherLeave,
    ];
}

/** @return array<string, mixed> */
function lvreqPayload(int $employeeId, string $start = '2031-03-03', string $end = '2031-03-07'): array
{
    return ['employee_id' => $employeeId, 'type' => 'annual', 'start_date' => $start, 'end_date' => $end, 'reason' => 'Trip'];
}

function lvreqCreate(User $actor, int $employeeId, string $start = '2031-03-03', string $end = '2031-03-07'): LeaveRequest
{
    return (new SaveLeaveRequestAction())->create($actor, lvreqPayload($employeeId, $start, $end));
}

it('lets a rep request leave for themselves but not for a colleague', function () {
    $w = lvreqWorld();
    Sanctum::actingAs($w['ann']);

    $this->postJson('/api/v1/hr/leave-requests', lvreqPayload($w['annE']))
        ->assertCreated()
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('days', 5)
        ->assertJsonPath('lock_version', 0)
        ->assertJsonPath('employee.name', 'Ann Rep');

    $this->postJson('/api/v1/hr/leave-requests', lvreqPayload($w['samE'], '2031-05-01', '2031-05-02'))->assertForbidden();

    Sanctum::actingAs($w['viewer']);
    $this->postJson('/api/v1/hr/leave-requests', lvreqPayload($w['annE'], '2031-06-01', '2031-06-02'))->assertForbidden();

    Sanctum::actingAs($w['manager']);
    $this->postJson('/api/v1/hr/leave-requests', lvreqPayload($w['samE'], '2031-05-01', '2031-05-02'))->assertCreated();
});

it('rejects overlapping and invalid dates', function () {
    $w = lvreqWorld();
    Sanctum::actingAs($w['ann']);

    $this->postJson('/api/v1/hr/leave-requests', lvreqPayload($w['annE']))->assertCreated();
    $this->postJson('/api/v1/hr/leave-requests', lvreqPayload($w['annE'], '2031-03-05', '2031-03-09'))
        ->assertUnprocessable()->assertJsonValidationErrors(['start_date']);
    $this->postJson('/api/v1/hr/leave-requests', lvreqPayload($w['annE'], '2031-04-09', '2031-04-01'))
        ->assertUnprocessable()->assertJsonValidationErrors(['end_date']);
    $this->postJson('/api/v1/hr/leave-requests', lvreqPayload($w['annE'], '2031-02-31', '2031-03-01'))
        ->assertUnprocessable()->assertJsonValidationErrors(['start_date']);
});

it('approves, bumps the version and reports availability per tenant', function () {
    $w = lvreqWorld();
    $leave = lvreqCreate($w['ann'], $w['annE']);
    $tenantId = (int) $w['tenant']->id;

    expect(LeaveRequest::isOutOn($tenantId, $w['annE'], '2031-03-05'))->toBeFalse();

    Sanctum::actingAs($w['manager']);
    $this->postJson("/api/v1/hr/leave-requests/{$leave->id}/transition", ['to_status' => 'approved', 'lock_version' => 0])
        ->assertOk()
        ->assertJsonPath('status', 'approved')
        ->assertJsonPath('lock_version', 1)
        ->assertJsonPath('decided_by', $w['manager']->id);

    expect(LeaveRequest::isOutOn($tenantId, $w['annE'], '2031-03-05'))->toBeTrue()
        ->and(LeaveRequest::isOutOn($tenantId, $w['annE'], '2031-03-10'))->toBeFalse()
        ->and(LeaveRequest::outEmployeeIds($tenantId, '2031-03-05'))->toBe([$w['annE']]);

    $this->getJson('/api/v1/hr/leave-requests/out?date=2031-03-05')
        ->assertOk()->assertJsonPath('employee_ids', [$w['annE']]);
});

it('returns 409 with the current row on a stale version', function () {
    $w = lvreqWorld();
    $leave = lvreqCreate($w['ann'], $w['annE']);
    Sanctum::actingAs($w['manager']);

    $this->postJson("/api/v1/hr/leave-requests/{$leave->id}/transition", ['to_status' => 'approved', 'lock_version' => 5])
        ->assertStatus(409)
        ->assertJsonPath('code', 'stale_leave_request')
        ->assertJsonPath('current.id', $leave->id)
        ->assertJsonPath('current.status', 'pending');
});

it('requires a note to reject and refuses invalid transitions', function () {
    $w = lvreqWorld();
    $leave = lvreqCreate($w['ann'], $w['annE']);
    Sanctum::actingAs($w['manager']);
    $url = "/api/v1/hr/leave-requests/{$leave->id}/transition";

    $this->postJson($url, ['to_status' => 'rejected', 'lock_version' => 0])
        ->assertUnprocessable()->assertJsonPath('code', 'note_required');

    $this->postJson($url, ['to_status' => 'rejected', 'lock_version' => 0, 'note' => 'Team is short'])
        ->assertOk()->assertJsonPath('status', 'rejected')->assertJsonPath('decision_note', 'Team is short');

    $this->postJson($url, ['to_status' => 'approved', 'lock_version' => 1])
        ->assertUnprocessable()->assertJsonPath('code', 'invalid_transition');
});

it('limits who may cancel and approve', function () {
    $w = lvreqWorld();
    $leave = lvreqCreate($w['ann'], $w['annE']);
    $url = "/api/v1/hr/leave-requests/{$leave->id}/transition";

    Sanctum::actingAs($w['sam']);
    $this->postJson($url, ['to_status' => 'cancelled', 'lock_version' => 0])->assertForbidden();

    Sanctum::actingAs($w['ann']);
    $this->postJson($url, ['to_status' => 'approved', 'lock_version' => 0])->assertForbidden();

    // An approved request can only be cancelled by hr.manage.
    Sanctum::actingAs($w['manager']);
    $this->postJson($url, ['to_status' => 'approved', 'lock_version' => 0])->assertOk();

    Sanctum::actingAs($w['ann']);
    $this->postJson($url, ['to_status' => 'cancelled', 'lock_version' => 1])->assertForbidden();

    Sanctum::actingAs($w['manager']);
    $this->postJson($url, ['to_status' => 'cancelled', 'lock_version' => 1])->assertOk()->assertJsonPath('status', 'cancelled');
    expect(LeaveRequest::isOutOn((int) $w['tenant']->id, $w['annE'], '2031-03-05'))->toBeFalse();

    // An owner may cancel their own PENDING request.
    $second = lvreqCreate($w['ann'], $w['annE'], '2031-08-01', '2031-08-02');
    Sanctum::actingAs($w['ann']);
    $this->postJson("/api/v1/hr/leave-requests/{$second->id}/transition", ['to_status' => 'cancelled', 'lock_version' => 0])
        ->assertOk()->assertJsonPath('status', 'cancelled');
});

it('keeps leave private and tenant-isolated', function () {
    $w = lvreqWorld();
    lvreqCreate($w['ann'], $w['annE']);
    lvreqCreate($w['sam'], $w['samE'], '2031-04-01', '2031-04-02');

    Sanctum::actingAs($w['ann']);
    expect($this->getJson('/api/v1/hr/leave-requests')->assertOk()->json('data'))->toHaveCount(1);

    Sanctum::actingAs($w['viewer']);
    expect($this->getJson('/api/v1/hr/leave-requests')->assertOk()->json('data'))->toHaveCount(0);

    Sanctum::actingAs($w['admin']);
    expect($this->getJson('/api/v1/hr/leave-requests')->assertOk()->json('data'))->toHaveCount(2);
    $this->getJson('/api/v1/hr/leave-requests/'.$w['otherLeave'])->assertNotFound();

    $this->postJson('/api/v1/hr/leave-requests/'.$w['otherLeave'].'/transition', ['to_status' => 'cancelled', 'lock_version' => 1])
        ->assertNotFound();
});

it('edits a pending request only, with the version check', function () {
    $w = lvreqWorld();
    $leave = lvreqCreate($w['ann'], $w['annE']);
    $body = ['type' => 'sick', 'start_date' => '2031-03-03', 'end_date' => '2031-03-04', 'reason' => 'Flu', 'lock_version' => 0];

    Sanctum::actingAs($w['ann']);
    $this->putJson("/api/v1/hr/leave-requests/{$leave->id}", $body)
        ->assertOk()->assertJsonPath('type', 'sick')->assertJsonPath('days', 2)->assertJsonPath('lock_version', 1);

    $this->putJson("/api/v1/hr/leave-requests/{$leave->id}", $body)->assertStatus(409);

    (new TransitionLeaveRequestAction())->execute($w['manager'], (int) $leave->id, 'approved', 1);

    $this->putJson("/api/v1/hr/leave-requests/{$leave->id}", ['lock_version' => 2] + $body)
        ->assertUnprocessable()->assertJsonPath('code', 'not_editable');
});

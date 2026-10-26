<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Marketing;

use App\Domain\Marketing\Automations\AutomationEnroller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * 9.6c-1: POST /api/v1/automations/{automation}/enroll  { contact_ids: [..] }
 * Manual enrollment (marketing.manage). Only an ACTIVE automation takes people; ids that are not this
 * tenant's contacts are silently ignored (nothing leaks about other tenants).
 */
final class AutomationEnrollmentController
{
    public function store(Request $request, int $automation): JsonResponse
    {
        $actor = $request->user();
        Gate::forUser($actor)->authorize('marketing.manage');

        $tenantId = (int) $actor->tenant_id;

        $row = DB::table('automations')
            ->where('tenant_id', $tenantId)
            ->where('id', $automation)
            ->first(['id', 'status']);

        abort_if($row === null, 404, 'Automation not found.');

        $data = $request->validate([
            'contact_ids' => ['required', 'array', 'min:1', 'max:500'],
            'contact_ids.*' => ['integer', 'min:1', 'distinct'],
        ]);

        if ($row->status !== 'active') {
            throw ValidationException::withMessages([
                'automation' => ['Only an active automation can enroll contacts.'],
            ]);
        }

        $counts = AutomationEnroller::enrollContacts($tenantId, (int) $row->id, array_map('intval', $data['contact_ids']));

        return response()->json(['data' => $counts]);
    }
}
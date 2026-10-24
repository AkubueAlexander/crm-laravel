<?php

namespace App\Http\Controllers\Api\Users;

use App\Domain\Users\Actions\CreateInvitationAction;
use App\Domain\Users\Actions\RevokeInvitationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Users\CreateInvitationRequest;
use App\Http\Resources\InvitationResource;
use App\Models\UserInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/** 8c.2c: the administrator side of invitations. The public preview/accept endpoints are separate. */
final class UserInvitationController extends Controller
{
    /** Pending (not yet accepted) invitations of the actor's tenant, newest first. */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('users.manage');

        // User/invitations have no tenant scope: this predicate IS the isolation (1.3).
        $rows = UserInvitation::query()
            ->where('tenant_id', (int) $request->user()->tenant_id)
            ->whereNull('accepted_at')
            ->with('inviter:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        // Built by hand: with unwrapped resources a plain collection would lose its "data" key.
        return response()->json([
            'data' => $rows->map(fn (UserInvitation $row) => (new InvitationResource($row))->resolve($request))->all(),
        ]);
    }

    /** 201 for a new invitation, 200 when it re-issued the pending one for the same email. */
    public function store(CreateInvitationRequest $request, CreateInvitationAction $action): JsonResponse
    {
        $validated = $request->validated();
        $invitation = $action->execute($request->user(), $validated['email'], $validated['role']);

        return (new InvitationResource($invitation->load('inviter:id,name')))
            ->response()
            ->setStatusCode($invitation->wasRecentlyCreated ? 201 : 200);
    }

    public function resend(Request $request, int $invitation, CreateInvitationAction $action): InvitationResource
    {
        Gate::authorize('users.manage');

        $existing = UserInvitation::query()
            ->where('tenant_id', (int) $request->user()->tenant_id)
            ->whereNull('accepted_at')
            ->findOrFail($invitation);

        $fresh = $action->execute($request->user(), $existing->email, $existing->role);

        return new InvitationResource($fresh->load('inviter:id,name'));
    }

    public function destroy(Request $request, int $invitation, RevokeInvitationAction $action): Response
    {
        $action->execute($request->user(), $invitation);

        return response()->noContent();
    }
}

<?php

namespace App\Http\Controllers\Api\Users;

use App\Domain\Users\Actions\AcceptInvitationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Users\AcceptInvitationRequest;
use App\Http\Resources\InvitationPreviewResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** 8c.2c: unauthenticated invitation endpoints. The token in the URL is the only credential. */
final class PublicInvitationController extends Controller
{
    public function show(string $token, AcceptInvitationAction $action): InvitationPreviewResource
    {
        return new InvitationPreviewResource($action->preview($token));
    }

    public function accept(AcceptInvitationRequest $request, string $token, AcceptInvitationAction $action): JsonResponse
    {
        $validated = $request->validated();

        $user = $action->execute($token, $validated['name'], $validated['password']);

        // Same sign-in steps as the login endpoint: named guard, then a fresh session id.
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json(['message' => 'Invitation accepted.']);
    }
}

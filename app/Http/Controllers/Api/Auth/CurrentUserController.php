<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;


class CurrentUserController extends Controller
{
    public function __invoke(Request $request): UserResource
    {
        $user = $request->user()->load('tenant');
        return new UserResource($user);
    }
}

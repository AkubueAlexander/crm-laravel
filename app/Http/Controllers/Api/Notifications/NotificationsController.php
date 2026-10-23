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

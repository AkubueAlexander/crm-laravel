<?php

use App\Broadcasting\UserChannel;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('tenant.{tenantId}.board.{boardId}', function ($user, $tenantId, $boardId) {
    return (int) $user->tenant_id === (int) $tenantId;
});

// 8b.2: per-user notification channel, tenant-scoped and re-verified on subscribe (1.5).
Broadcast::channel('tenant.{tenantId}.user.{userId}', UserChannel::class);

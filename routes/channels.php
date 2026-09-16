<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('tenant.{tenantId}.board.{boardId}', function ($user, $tenantId, $boardId) {
    return (int) $user->tenant_id === (int) $tenantId;
});

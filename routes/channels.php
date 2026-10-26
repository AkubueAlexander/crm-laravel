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

// 9.4d: live campaign progress. CampaignsChannel re-verifies tenant, active user and marketing.view at subscribe time.
\Illuminate\Support\Facades\Broadcast::channel('tenant.{tenantId}.campaigns', \App\Broadcasting\CampaignsChannel::class);

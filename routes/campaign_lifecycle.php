<?php

use App\Http\Controllers\Api\Marketing\CampaignLifecycleController;
use Illuminate\Support\Facades\Route;

// 9.4a: required from routes/api.php inside the authenticated /api/v1 group, so no prefix or auth here.
// Single transition endpoint, same principle as POST deals/{deal}/transition (3.1).
Route::post('campaigns/{campaign}/transition', [CampaignLifecycleController::class, 'transition'])
    ->whereNumber('campaign')
    ->middleware('throttle:30,1');
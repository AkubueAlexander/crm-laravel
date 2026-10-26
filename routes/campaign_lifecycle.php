<?php

use App\Http\Controllers\Api\Marketing\CampaignLifecycleController;
use App\Http\Controllers\Api\Marketing\CampaignPickerOptionsController;
use Illuminate\Support\Facades\Route;

// 9.4a: required from routes/api.php inside the authenticated /api/v1 group, so no prefix or auth here.
// Single transition endpoint, same principle as POST deals/{deal}/transition (3.1).
Route::post('campaigns/{campaign}/transition', [CampaignLifecycleController::class, 'transition'])
    ->whereNumber('campaign')
    ->middleware('throttle:30,1');

// 9.4e: picker sources for the campaign form. Under campaigns/ with literal names so they can never collide
// with segments/{segment} or the email-template routes (which this file is required after).
Route::get('campaigns/segment-options', [CampaignPickerOptionsController::class, 'segments'])
    ->middleware('throttle:60,1');

Route::get('campaigns/template-options', [CampaignPickerOptionsController::class, 'templates'])
    ->middleware('throttle:60,1');
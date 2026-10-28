<?php

namespace App\Http\Controllers\Api\Settings;

use App\Domain\Settings\Actions\UpdateQuoteSettingsAction;
use App\Domain\Settings\QuoteSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateQuoteSettingsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class QuoteSettingsController extends Controller
{
    public function show(Request $request, QuoteSettings $settings): JsonResponse
    {
        Gate::authorize('settings.manage');

        return response()->json($settings->forTenant((int) $request->user()->tenant_id));
    }

    public function update(UpdateQuoteSettingsRequest $request, UpdateQuoteSettingsAction $action): JsonResponse
    {
        return response()->json($action->execute($request->user(), $request->validated('approval_threshold')));
    }
}

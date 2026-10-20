<?php

namespace App\Http\Controllers\Api\Contacts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\UpdateContactMatchSettingsRequest;
use App\Models\ContactMatchSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;


class ContactMatchSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        Gate::authorize('contacts.update');

        return response()->json([
            'match_threshold' => ContactMatchSettings::currentThreshold(),
            'default_threshold' => ContactMatchSettings::DEFAULT_THRESHOLD,
        ]);
    }

    public function update(UpdateContactMatchSettingsRequest $request): JsonResponse
    {
        $settings = ContactMatchSettings::query()->first() ?? new ContactMatchSettings();
        $settings->match_threshold = $request->validated('match_threshold');
        $settings->save(); // BelongsToTenant's saving hook stamps tenant_id when empty.

        return response()->json([
            'match_threshold' => $settings->match_threshold,
            'default_threshold' => ContactMatchSettings::DEFAULT_THRESHOLD,
        ]);
    }
}

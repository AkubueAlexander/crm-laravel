<?php

namespace App\Http\Controllers\Api\Contacts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\UpdateContactMatchSettingsRequest;
use App\Models\ContactMatchSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Singleton-per-tenant settings row (contact_match_settings.tenant_id is UNIQUE).
 * show()/update() rather than full CRUD -- there is exactly one row per tenant,
 * created lazily on first update, read through the tenant global scope so no
 * tenant id is ever passed around and cross-tenant reads/writes are impossible.
 */
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
<?php

namespace App\Http\Controllers\Api\Settings;

use App\Domain\Settings\Actions\UpdatePipelineSettingsAction;
use App\Domain\Settings\Actions\UpdateTenantBrandingAction;
use App\Domain\Settings\PipelineSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdatePipelineSettingsRequest;
use App\Http\Requests\Settings\UpdateTenantBrandingRequest;
use App\Http\Resources\TenantResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class TenantSettingsController extends Controller
{
    public function pipeline(Request $request, PipelineSettings $settings): JsonResponse
    {
        Gate::authorize('settings.manage');

        return response()->json($settings->forTenant((int) $request->user()->tenant_id));
    }

    public function updatePipeline(UpdatePipelineSettingsRequest $request, UpdatePipelineSettingsAction $action): JsonResponse
    {
        return response()->json($action->execute($request->user(), $request->validated('stages')));
    }

    public function updateBranding(UpdateTenantBrandingRequest $request, UpdateTenantBrandingAction $action): TenantResource
    {
        $tenant = $action->execute($request->user(), $request->validated(), $request->file('logo'));

        return new TenantResource($tenant);
    }

    public function logo(Request $request): StreamedResponse
    {
        // The tenant comes from the session, never from the request (1.3).
        $path = UpdateTenantBrandingAction::logoPath((int) $request->user()->tenant_id);
        $disk = Storage::disk(UpdateTenantBrandingAction::DISK);

        abort_unless($disk->exists($path), 404);

        // The URL carries a content-hash version, so a long private cache is safe.
        return $disk->response($path, 'logo.png', [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

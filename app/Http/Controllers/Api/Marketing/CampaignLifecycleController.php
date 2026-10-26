<?php

namespace App\Http\Controllers\Api\Marketing;

use App\Domain\Marketing\Actions\TransitionCampaignAction;
use App\Domain\Marketing\Exceptions\CampaignTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Marketing\TransitionCampaignRequest;
use App\Http\Resources\CampaignLifecycleResource;
use Carbon\CarbonImmutable;

class CampaignLifecycleController extends Controller
{
    /** POST /api/v1/campaigns/{campaign}/transition  { to_status, lock_version, scheduled_at? } */
    public function transition(TransitionCampaignRequest $request, int $campaign, TransitionCampaignAction $action)
    {
        $scheduledAt = $request->validated('scheduled_at');

        try {
            $updated = $action->execute(
                $request->user(),
                $campaign,
                $request->validated('to_status'),
                (int) $request->validated('lock_version'),
                $scheduledAt ? CarbonImmutable::parse($scheduledAt) : null,
            );
        } catch (CampaignTransitionException $e) {
            if ($e->isStale() && $e->campaign !== null) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'errors' => [],
                    'code' => $e->reason,
                    'current' => new CampaignLifecycleResource($e->campaign),
                ], 409);
            }

            return response()->json([
                'message' => $e->getMessage(),
                'errors' => [],
                'code' => $e->reason,
            ], 422);
        }

        return new CampaignLifecycleResource($updated);
    }
}
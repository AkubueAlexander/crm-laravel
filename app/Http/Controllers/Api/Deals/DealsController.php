<?php

namespace App\Http\Controllers\Api\Deals;

use App\Domain\Deals\Actions\TransitionDealAction;
use App\Domain\Deals\Exceptions\StaleDealException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Deals\TransitionDealRequest;
use App\Http\Resources\DealResource;
use App\Models\Deal;

class DealsController extends Controller
{
    // 3.1: the one mutation endpoint — POST /api/v1/deals/{deal}/transition
    public function transition(TransitionDealRequest $request, Deal $deal, TransitionDealAction $action)
    {
        try {
            $updated = $action->execute(
                $deal,
                $request->validated('to_stage'),
                $request->validated('lock_version'),
                $request->user(),
            );
        } catch (StaleDealException $e) {
            return response()->json([
                'message' => 'This deal was updated by someone else — refresh to see the latest stage.',
                'errors' => [],
                'code' => 'stale_deal',
                'current' => new DealResource($e->deal),
            ], 409);
        }

        return new DealResource($updated);
    }
}

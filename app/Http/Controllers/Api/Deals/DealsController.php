<?php

namespace App\Http\Controllers\Api\Deals;

use App\Domain\Deals\Actions\TransitionDealAction;
use App\Domain\Deals\Exceptions\StaleDealException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Deals\TransitionDealRequest;
use App\Http\Resources\DealResource;
use App\Models\Deal;
use Illuminate\Http\Request;
use Spatie\ModelStates\Exceptions\TransitionNotFound;

class DealsController extends Controller
{

    public function index(Request $request)
    {
        abort_unless($request->user()->can('deals.view'), 403);

        $boardId = $request->integer('board_id', 1);

        $deals = Deal::query()
            ->where('board_id', $boardId)
            ->orderBy('created_at')
            ->get();

        return DealResource::collection($deals);
    }

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
        } catch (TransitionNotFound $e) {
            return response()->json([
                'message' => 'That stage change isn\'t allowed from the deal\'s current stage.',
                'errors' => [],
                'code' => 'invalid_transition',
            ], 422);
        }

        return new DealResource($updated);
    }
}

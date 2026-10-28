<?php

namespace App\Http\Controllers\Api\Quotes;

use App\Domain\Quotes\Actions\TransitionQuoteAction;
use App\Domain\Quotes\Exceptions\QuoteTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Quotes\TransitionQuoteRequest;
use App\Http\Resources\QuoteResource;
use Illuminate\Http\JsonResponse;

// {quote} is a plain id on purpose (no route-model binding): the Action looks it up with an explicit tenant predicate.
final class QuoteLifecycleController extends Controller
{
    public function transition(TransitionQuoteRequest $request, int $quote, TransitionQuoteAction $action): JsonResponse
    {
        $v = $request->validated();

        try {
            $updated = $action->execute($request->user(), $quote, $v['to_status'], (int) $v['lock_version'], $v['note'] ?? null);
        } catch (QuoteTransitionException $e) {
            if ($e->isStale() && $e->quote !== null) {
                // 409: the client's lock_version is behind. Return the current quote so it can reconcile.
                return response()->json([
                    'message' => $e->getMessage(),
                    'code' => $e->reason,
                    'current' => (new QuoteResource($e->quote))->resolve($request),
                ], 409);
            }

            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['to_status' => [$e->getMessage()]],
                'code' => $e->reason,
            ], 422);
        }

        return (new QuoteResource($updated))->response();
    }
}

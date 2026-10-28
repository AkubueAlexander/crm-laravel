<?php

namespace App\Http\Controllers\Api\Invoices;

use App\Domain\Invoices\Actions\TransitionInvoiceAction;
use App\Domain\Invoices\Exceptions\InvoiceTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Invoices\TransitionInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use Illuminate\Http\JsonResponse;

// {invoice} is a plain id on purpose (no route-model binding): the Action looks it up with an explicit tenant predicate.
final class InvoiceLifecycleController extends Controller
{
    public function transition(TransitionInvoiceRequest $request, int $invoice, TransitionInvoiceAction $action): JsonResponse
    {
        $v = $request->validated();

        try {
            $updated = $action->execute($request->user(), $invoice, $v['to_status'], (int) $v['lock_version'], $v['note'] ?? null);
        } catch (InvoiceTransitionException $e) {
            if ($e->isStale() && $e->invoice !== null) {
                // 409: the client's lock_version is behind. Return the current invoice so it can reconcile.
                return response()->json([
                    'message' => $e->getMessage(),
                    'code' => $e->reason,
                    'current' => (new InvoiceResource($e->invoice))->resolve($request),
                ], 409);
            }

            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['to_status' => [$e->getMessage()]],
                'code' => $e->reason,
            ], 422);
        }

        return (new InvoiceResource($updated))->response();
    }
}

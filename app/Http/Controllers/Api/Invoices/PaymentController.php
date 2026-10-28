<?php

namespace App\Http\Controllers\Api\Invoices;

use App\Domain\Invoices\Actions\RecordPaymentAction;
use App\Domain\Invoices\Actions\VoidPaymentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Invoices\RecordPaymentRequest;
use App\Http\Requests\Invoices\VoidPaymentRequest;
use App\Http\Resources\InvoiceResource;
use Illuminate\Http\JsonResponse;

// {invoice} / {payment} are plain ids on purpose (no route-model binding): the Actions look them up with an explicit
// tenant predicate. Validation failures from the Actions are ValidationExceptions (422 with field errors).
final class PaymentController extends Controller
{
    public function store(RecordPaymentRequest $request, int $invoice, RecordPaymentAction $action): JsonResponse
    {
        $v = $request->validated();

        $updated = $action->execute(
            $request->user(),
            $invoice,
            (string) $v['amount'],
            (string) $v['method'],
            $v['paid_on'] ?? null,
            $v['reference'] ?? null,
            $v['note'] ?? null,
        );

        return (new InvoiceResource($updated))->response()->setStatusCode(201);
    }

    public function void(VoidPaymentRequest $request, int $payment, VoidPaymentAction $action): JsonResponse
    {
        $v = $request->validated();

        $updated = $action->execute($request->user(), $payment, (string) $v['reason']);

        return (new InvoiceResource($updated))->response();
    }
}

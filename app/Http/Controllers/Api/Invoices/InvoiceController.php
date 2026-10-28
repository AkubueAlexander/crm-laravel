<?php

namespace App\Http\Controllers\Api\Invoices;

use App\Domain\Invoices\Actions\CreateInvoiceFromQuoteAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Invoices\CreateInvoiceRequest;
use App\Http\Requests\Invoices\ListInvoicesRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

// {invoice} is a plain id on purpose (no route-model binding): every lookup carries an explicit tenant predicate.
final class InvoiceController extends Controller
{
    public function index(ListInvoicesRequest $request): AnonymousResourceCollection
    {
        $v = $request->validated();
        $tenantId = (int) $request->user()->tenant_id;
        $q = trim((string) ($v['q'] ?? ''));
        $direction = $v['direction'] ?? 'desc';
        $column = ListInvoicesRequest::SORT_COLUMNS[$v['sort'] ?? 'invoice_number'];

        $invoices = Invoice::query()
            ->where('tenant_id', $tenantId)
            ->withCount('lines')
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.addcslashes($q, '\\%_').'%';
                $query->where(function ($w) use ($like) {
                    $w->where('invoice_number', 'ilike', $like)->orWhere('title', 'ilike', $like);
                });
            })
            ->when($v['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy($column, $direction)
            ->orderBy('id', $direction) // stable tiebreaker so pages never overlap
            ->paginate($v['per_page'] ?? 25)
            ->withQueryString();

        return InvoiceResource::collection($invoices);
    }

    public function show(Request $request, int $invoice): InvoiceResource
    {
        Gate::authorize('invoices.view');

        return new InvoiceResource(
            Invoice::query()
                ->where('tenant_id', (int) $request->user()->tenant_id)
                ->with('lines')
                ->findOrFail($invoice),
        );
    }

    public function store(CreateInvoiceRequest $request, CreateInvoiceFromQuoteAction $action): JsonResponse
    {
        $v = $request->validated();

        $invoice = $action->execute(
            $request->user(),
            (int) $v['quote_id'],
            isset($v['payment_terms_days']) ? (int) $v['payment_terms_days'] : null,
        );

        return (new InvoiceResource($invoice))->response()->setStatusCode(201);
    }
}

<?php

namespace App\Http\Controllers\Api\Quotes;

use App\Domain\Quotes\Actions\DeleteQuoteAction;
use App\Domain\Quotes\Actions\SaveQuoteAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Quotes\ListQuotesRequest;
use App\Http\Requests\Quotes\SaveQuoteRequest;
use App\Http\Resources\QuoteResource;
use App\Models\Quote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

// {quote} is a plain id on purpose (no route-model binding): every lookup carries an explicit tenant predicate.
final class QuoteController extends Controller
{
    public function index(ListQuotesRequest $request): AnonymousResourceCollection
    {
        $v = $request->validated();
        $tenantId = (int) $request->user()->tenant_id;
        $q = trim((string) ($v['q'] ?? ''));
        $direction = $v['direction'] ?? 'desc';
        $column = ListQuotesRequest::SORT_COLUMNS[$v['sort'] ?? 'quote_number'];

        $quotes = Quote::query()
            ->where('tenant_id', $tenantId)
            ->withCount('lines')
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.addcslashes($q, '\\%_').'%';
                $query->where(function ($w) use ($like) {
                    $w->where('quote_number', 'ilike', $like)->orWhere('title', 'ilike', $like);
                });
            })
            ->when($v['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy($column, $direction)
            ->orderBy('id', $direction) // stable tiebreaker so pages never overlap
            ->paginate($v['per_page'] ?? 25)
            ->withQueryString();

        return QuoteResource::collection($quotes);
    }

    public function show(Request $request, int $quote): QuoteResource
    {
        Gate::authorize('quotes.view');

        return new QuoteResource(
            Quote::query()
                ->where('tenant_id', (int) $request->user()->tenant_id)
                ->with('lines')
                ->findOrFail($quote),
        );
    }

    public function store(SaveQuoteRequest $request, SaveQuoteAction $action): JsonResponse
    {
        $quote = $action->execute($request->user(), null, $request->validated());

        return (new QuoteResource($quote))->response()->setStatusCode(201);
    }

    public function update(SaveQuoteRequest $request, int $quote, SaveQuoteAction $action): QuoteResource
    {
        return new QuoteResource($action->execute($request->user(), $quote, $request->validated()));
    }

    public function destroy(Request $request, int $quote, DeleteQuoteAction $action): Response
    {
        $action->execute($request->user(), $quote);

        return response()->noContent();
    }
}

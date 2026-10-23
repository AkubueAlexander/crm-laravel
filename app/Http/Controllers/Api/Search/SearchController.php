<?php

namespace App\Http\Controllers\Api\Search;

use App\Domain\Search\SearchService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Search\SearchRequest;
use Illuminate\Http\JsonResponse;

final class SearchController extends Controller
{
    public function __invoke(SearchRequest $request, SearchService $service): JsonResponse
    {
        $term = $request->term();

        return response()->json([
            // (object) so an actor with no *.view permissions gets {} rather than [].
            'data' => (object) $service->search($request->user(), $term),
            'query' => $term,
        ]);
    }
}

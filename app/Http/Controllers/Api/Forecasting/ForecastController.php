<?php

namespace App\Http\Controllers\Api\Forecasting;

use App\Domain\Deals\Actions\TransitionDealAction;
use App\Domain\Forecasting\ForecastingEngine;
use App\Domain\Forecasting\PipelineProbabilities;
use App\Http\Controllers\Controller;
use App\Http\Resources\ForecastResource;
use App\Models\Deal;
use Illuminate\Http\Request;

class ForecastController extends Controller
{
    // 4.1: tenant is implicit from session (ResolveTenant + Deal's own
    // global scope) — never accepted as a client-supplied parameter.
    public function __invoke(Request $request, ForecastingEngine $engine, PipelineProbabilities $probabilities)
    {
        abort_unless($request->user()->can('forecast.view'), 403);

        $tenantId = $request->user()->tenant_id;

        $deals = Deal::query()
            ->whereNotIn('state', [
                array_search(\App\Domain\Deals\States\Lost::class, TransitionDealAction::STATE_MAP),
            ])
            ->get(['state', 'amount'])
            ->map(fn (Deal $deal) => [
                'stage' => array_search($deal->state::class, TransitionDealAction::STATE_MAP) ?: 'unknown',
                'amount' => (float) ($deal->amount ?? 0),
            ])
            ->toArray();

        $result = $engine->calculate($deals, $probabilities->resolve($tenantId));

        return new ForecastResource($result);
    }
}

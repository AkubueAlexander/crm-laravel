<?php

namespace App\Http\Controllers\Api\Marketing;

use App\Domain\Marketing\CampaignReportBuilder;
use App\Http\Controllers\Controller;
use App\Http\Resources\CampaignReportResource;
use App\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

// 9.10: {campaign} is a plain id (no route-model binding): the lookup carries an explicit tenant predicate,
// so another tenant's campaign is a 404.
final class CampaignReportController extends Controller
{
    public function show(Request $request, int $campaign, CampaignReportBuilder $builder): CampaignReportResource
    {
        Gate::authorize('marketing.view');

        $model = Campaign::query()
            ->where('tenant_id', (int) $request->user()->tenant_id)
            ->findOrFail($campaign);

        return new CampaignReportResource($builder->build($model));
    }
}

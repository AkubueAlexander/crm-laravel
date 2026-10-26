<?php

namespace App\Http\Controllers\Api\Marketing;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use App\Models\Segment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * 9.4e: picker sources for the campaign form. {id, name} only, own tenant only, capped, ordered by name.
 * The list endpoints page at 15/25 rows, which is wrong for a <select>.
 */
final class CampaignPickerOptionsController extends Controller
{
    public const CAP = 200;

    public function segments(Request $request): JsonResponse
    {
        Gate::authorize('marketing.view');

        $rows = Segment::query()
            ->where('tenant_id', (int) $request->user()->tenant_id)
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::CAP)
            ->get(['id', 'name'])
            ->map(fn (Segment $s) => ['id' => (int) $s->id, 'name' => (string) $s->name])
            ->all();

        return response()->json(['data' => $rows]);
    }

    public function templates(Request $request): JsonResponse
    {
        Gate::authorize('marketing.view');

        $rows = EmailTemplate::query()
            ->where('tenant_id', (int) $request->user()->tenant_id)
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::CAP)
            ->get(['id', 'name'])
            ->map(fn (EmailTemplate $t) => ['id' => (int) $t->id, 'name' => (string) $t->name])
            ->all();

        return response()->json(['data' => $rows]);
    }
}
<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Domain\Accounting\LedgerReports;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

// 12.0b: read-only operational reports. accounting.view; tenant comes from the session, never from the request.
final class LedgerReportController extends Controller
{
    public function trialBalance(Request $request, LedgerReports $reports): JsonResponse
    {
        Gate::authorize('accounting.view');
        $v = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json($reports->trialBalance((int) $request->user()->tenant_id, $v['as_of'] ?? now()->toDateString()));
    }

    public function incomeStatement(Request $request, LedgerReports $reports): JsonResponse
    {
        Gate::authorize('accounting.view');
        $v = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return response()->json($reports->incomeStatement(
            (int) $request->user()->tenant_id,
            $v['from'] ?? now()->startOfYear()->toDateString(),
            $v['to'] ?? now()->toDateString(),
        ));
    }

    public function balanceSheet(Request $request, LedgerReports $reports): JsonResponse
    {
        Gate::authorize('accounting.view');
        $v = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json($reports->balanceSheet((int) $request->user()->tenant_id, $v['as_of'] ?? now()->toDateString()));
    }

    /** One account's ledger with a running balance: { data, meta, account, opening_balance }. */
    public function ledger(Request $request, LedgerReports $reports): JsonResponse
    {
        Gate::authorize('accounting.view');
        $v = $request->validate([
            'account_id' => ['required', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $result = $reports->accountLedger(
            (int) $request->user()->tenant_id,
            (int) $v['account_id'],
            $v['from'] ?? null,
            $v['to'] ?? null,
            (int) ($v['per_page'] ?? 50),
            (int) ($v['page'] ?? 1),
        );

        $account = $result['account'];

        return JsonResource::collection($result['paginator'])
            ->additional([
                'account' => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'type' => $account->type],
                'opening_balance' => $result['opening'],
            ])
            ->response();
    }
}
